<?php

namespace App\Tests\Api;

use App\Entity\PushSubscription;
use App\Entity\User;

class PushSubscriptionApiTest extends AuthenticatedApiTestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /**
     * @param array<string, string> $overrides
     */
    private function subscribe(array $overrides = []): void
    {
        $this->call('POST', '/push-subscriptions', [], $overrides + [
            'endpoint' => self::ENDPOINT,
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ]);
    }

    public function testSubscribeRequiresAuthentication(): void
    {
        $this->client->setServerParameter('HTTP_Authorization', '');

        $this->subscribe();

        $this->assertResponseStatusCodeSame(401);
    }

    public function testSubscribe(): void
    {
        $this->subscribe();

        $this->assertResponseStatusCodeSame(201);

        $subscription = $this->em->getRepository(PushSubscription::class)->findOneBy(['endpoint' => self::ENDPOINT]);
        $this->assertNotNull($subscription);
        $this->assertSame($this->user->id, $subscription->user->id);
    }

    /**
     * Le front repousse son abonnement à chaque démarrage : sans upsert, le
     * second envoi violerait la contrainte d'unicité sur l'endpoint.
     */
    public function testSubscribingTwiceUpdatesInsteadOfDuplicating(): void
    {
        $this->subscribe();
        $this->subscribe(['auth' => 'GHrDDGrgLRFXTkOOUHK1Eg']);

        $this->assertResponseStatusCodeSame(201);

        $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['endpoint' => self::ENDPOINT]);
        $this->assertCount(1, $subscriptions);
        $this->assertSame('GHrDDGrgLRFXTkOOUHK1Eg', $subscriptions[0]->auth);
    }

    /**
     * Un appareil qui change de mains doit suivre son nouvel utilisateur, sinon
     * l'ancien continuerait de recevoir les notifications du nouveau.
     */
    public function testSubscribingFromAnotherAccountReassignsTheDevice(): void
    {
        $this->subscribe();

        $other = $this->createUser('autre');
        $this->loginUser('autre');
        $this->subscribe();

        $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['endpoint' => self::ENDPOINT]);
        $this->assertCount(1, $subscriptions);
        $this->assertSame($other->id, $subscriptions[0]->user->id);
    }

    public function testInvalidEndpointIsRejected(): void
    {
        $this->subscribe(['endpoint' => 'pas-une-url']);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testListOnlyReturnsOwnSubscriptions(): void
    {
        $this->subscribe();
        $this->createSubscriptionFor($this->createUser('voisin'), 'https://web.push.apple.com/voisin');

        $this->call('GET', '/push-subscriptions');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $data['member']);
        $this->assertSame('Appareil', $data['member'][0]['deviceName']);
    }

    public function testCannotDeleteSomeoneElseSubscription(): void
    {
        $subscription = $this->createSubscriptionFor($this->createUser('voisin'), 'https://web.push.apple.com/voisin');

        $this->call('DELETE', '/push-subscriptions/'.$subscription->id);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteOwnSubscription(): void
    {
        // L'id est relu avant l'appel : Doctrine le remet à null sur l'instance
        // partagée quand l'entité est supprimée.
        $id = $this->createSubscriptionFor($this->user, self::ENDPOINT)->id;

        $this->call('DELETE', '/push-subscriptions/'.$id);

        $this->assertResponseStatusCodeSame(204);
        $this->assertNull($this->em->getRepository(PushSubscription::class)->find($id));
    }

    private function createSubscriptionFor(User $user, string $endpoint): PushSubscription
    {
        $subscription = new PushSubscription();
        $subscription->user = $user;
        $subscription->endpoint = $endpoint;
        $subscription->p256dh = 'p256dh';
        $subscription->auth = 'auth';

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
