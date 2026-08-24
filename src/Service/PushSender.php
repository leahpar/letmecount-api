<?php

namespace App\Service;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoie une notification push aux appareils d'un ou plusieurs utilisateurs.
 *
 * Un abonnement dont le service de push répond 404 ou 410 est mort
 * (PWA désinstallée, données de site effacées) : c'est la seule façon dont il
 * nous le fait savoir, et sans suppression la table se remplit d'endpoints qui
 * échouent à chaque envoi. Les autres échecs sont seulement journalisés.
 */
class PushSender
{
    /**
     * Une notification de dépense n'a plus d'intérêt au bout d'un jour : passé ce
     * délai, le service de push cesse d'essayer de la remettre.
     */
    private const TTL = 86400;

    /**
     * Un service de push lent ne doit pas immobiliser le worker FPM.
     */
    private const TIMEOUT = 5;

    /**
     * Sans clés configurées, chaque écriture passerait par ici : on ne le
     * signale qu'une fois par requête plutôt qu'à chaque dépense créée.
     */
    private bool $vapidWarningLogged = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PushSubscriptionRepository $repository,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $publicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private readonly string $privateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')]
        private readonly string $subject,
    ) {
    }

    /**
     * @param iterable<User> $users
     * @param array{title: string, body: string, url?: string} $payload
     *
     * @return int nombre d'appareils réellement notifiés
     */
    public function send(iterable $users, array $payload): int
    {
        // Sans clés VAPID configurées, on ne notifie pas — mais on ne fait pas
        // non plus échouer l'écriture qui a déclenché l'envoi.
        if ('' === $this->publicKey || '' === $this->privateKey) {
            if (!$this->vapidWarningLogged) {
                $this->logger->warning('[Push] Clés VAPID absentes, notifications désactivées.');
                $this->vapidWarningLogged = true;
            }

            return 0;
        }

        $subscriptions = $this->subscriptionsOf($users);
        if ([] === $subscriptions) {
            return 0;
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Une charge utile inencodable (UTF-8 invalide dans un titre de
            // dépense, par exemple) ne doit pas remonter jusqu'à l'appelant :
            // l'envoi est accessoire, l'écriture qui l'a déclenché ne l'est pas.
            $this->logger->error('[Push] Charge utile inencodable : {message}', ['message' => $e->getMessage()]);

            return 0;
        }

        $webPush = new WebPush(
            ['VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ]],
            ['TTL' => self::TTL],
            new Psr18Client($this->httpClient->withOptions(['timeout' => self::TIMEOUT])),
            // Sans logger, la lib signale l'absence de GMP/BCMath par un
            // trigger_error() que le gestionnaire d'erreurs de Symfony convertit
            // en exception : la construction échoue avant le premier envoi.
            logger: $this->logger,
        );

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                // L'encodage doit être passé explicitement : la lib retombe encore
                // sur "aesgcm", l'encodage historique d'avant la RFC 8291.
                new Subscription(
                    $subscription->endpoint,
                    $subscription->p256dh,
                    $subscription->auth,
                    ContentEncoding::aes128gcm
                ),
                $body
            );
        }

        $delivered = 0;

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                ++$delivered;
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $this->forget($report->getEndpoint());
                continue;
            }

            $this->logger->warning('[Push] Échec d\'envoi : {reason}', [
                'reason' => $report->getReason(),
                'endpoint' => $report->getEndpoint(),
            ]);
        }

        $this->entityManager->flush();

        return $delivered;
    }

    /**
     * @param iterable<User> $users
     *
     * @return array<PushSubscription>
     */
    private function subscriptionsOf(iterable $users): array
    {
        $users = $users instanceof \Traversable ? iterator_to_array($users) : $users;

        return [] === $users
            ? []
            : $this->repository->findBy(['user' => $users]);
    }

    private function forget(string $endpoint): void
    {
        $subscription = $this->repository->findOneBy(['endpoint' => $endpoint]);
        if (null === $subscription) {
            return;
        }

        $this->logger->info('[Push] Abonnement expiré, suppression.', ['endpoint' => $endpoint]);
        $this->entityManager->remove($subscription);
    }
}
