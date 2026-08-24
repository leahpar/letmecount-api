<?php

namespace App\Tests\Service;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\PushSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PushSenderTest extends TestCase
{
    /** Paire VAPID de test, sans usage ailleurs. */
    private const PUBLIC_KEY = 'BLfO0GzOFKLjj1w_NygjMS9aSU-lONn_m5I0bRrvD_OwrrcXallurwSRaqplbArYaPgQL3ht8G5thU05WRD41LE';
    private const PRIVATE_KEY = 'woraIO2RRusx7cZT4Wq8epSgyN5TSTLlIPhiWNI53J4';

    /**
     * Un abonnement réaliste : la charge utile est réellement chiffrée avec cette
     * clé, une valeur bidon ferait échouer l'envoi avant la requête HTTP.
     */
    private function subscription(User $user, string $endpoint): PushSubscription
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key);

        $encode = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $subscription = new PushSubscription();
        $subscription->user = $user;
        $subscription->endpoint = $endpoint;
        $subscription->p256dh = $encode("\x04".$details['ec']['x'].$details['ec']['y']);
        $subscription->auth = $encode(random_bytes(16));

        return $subscription;
    }

    /**
     * @param array<PushSubscription> $subscriptions
     * @param array<int> $statuses réponse du service de push, dans l'ordre des abonnements
     * @param list<string> $sentTo rempli avec les URL réellement appelées
     */
    private function sender(
        array $subscriptions,
        array $statuses,
        ?EntityManagerInterface $entityManager = null,
        array &$sentTo = [],
        string $publicKey = self::PUBLIC_KEY,
    ): PushSender {
        $repository = $this->createMock(PushSubscriptionRepository::class);
        $repository->method('findBy')->willReturn($subscriptions);
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria) => array_values(array_filter(
                $subscriptions,
                fn (PushSubscription $s) => $s->endpoint === ($criteria['endpoint'] ?? null)
            ))[0] ?? null
        );

        $client = new MockHttpClient(function (string $method, string $url) use (&$sentTo, &$statuses): MockResponse {
            $sentTo[] = $url;

            return new MockResponse('', ['http_code' => array_shift($statuses) ?? 201]);
        });

        return new PushSender(
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $repository,
            $client,
            new NullLogger(),
            $publicKey,
            self::PRIVATE_KEY,
            'mailto:test@example.com',
        );
    }

    public function testSendsOneRequestPerSubscription(): void
    {
        $user = new User();
        $subscriptions = [
            $this->subscription($user, 'https://fcm.googleapis.com/fcm/send/un'),
            $this->subscription($user, 'https://web.push.apple.com/deux'),
        ];
        $sentTo = [];

        $this->sender($subscriptions, [201, 201], null, $sentTo)
            ->send([$user], ['title' => 'Courses', 'body' => '12 € pour toi']);

        $this->assertSame(
            ['https://fcm.googleapis.com/fcm/send/un', 'https://web.push.apple.com/deux'],
            $sentTo
        );
    }

    /**
     * 410 Gone est la seule façon dont un service de push signale qu'un
     * abonnement est mort. Sans suppression, la table se remplit d'endpoints qui
     * échouent à chaque envoi.
     */
    public function testExpiredSubscriptionIsDeleted(): void
    {
        $user = new User();
        $subscription = $this->subscription($user, 'https://fcm.googleapis.com/fcm/send/mort');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($subscription);

        $this->sender([$subscription], [410], $entityManager)
            ->send([$user], ['title' => 'Courses', 'body' => '12 € pour toi']);
    }

    /**
     * Un service de push en panne ne doit pas coûter son abonnement à l'utilisateur.
     */
    public function testServerErrorKeepsTheSubscription(): void
    {
        $user = new User();
        $subscription = $this->subscription($user, 'https://fcm.googleapis.com/fcm/send/casse');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $this->sender([$subscription], [500], $entityManager)
            ->send([$user], ['title' => 'Courses', 'body' => '12 € pour toi']);
    }

    /**
     * Sans clés VAPID configurées, l'écriture qui a déclenché l'envoi ne doit pas
     * échouer pour autant.
     */
    public function testMissingVapidKeysSendsNothing(): void
    {
        $user = new User();
        $sentTo = [];

        $this->sender([$this->subscription($user, 'https://fcm.googleapis.com/fcm/send/un')], [201], null, $sentTo, '')
            ->send([$user], ['title' => 'Courses', 'body' => '12 € pour toi']);

        $this->assertSame([], $sentTo);
    }

    public function testNoRecipientSendsNothing(): void
    {
        $sentTo = [];

        $this->sender([], [], null, $sentTo)->send([], ['title' => 'Courses', 'body' => 'rien']);

        $this->assertSame([], $sentTo);
    }
}
