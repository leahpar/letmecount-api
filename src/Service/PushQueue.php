<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Accumule les notifications pendant la requête, et les envoie une fois la
 * réponse rendue.
 *
 * L'envoi met en jeu un aller-retour HTTP par appareil abonné, vers des services
 * tiers (FCM, Apple). Le faire depuis un listener Doctrine ferait attendre
 * l'utilisateur pendant ces allers-retours, à l'intérieur de sa transaction
 * d'écriture. La prod tourne en PHP-FPM : `fastcgi_finish_request` libère le
 * client avant kernel.terminate, donc personne n'attend.
 */
class PushQueue
{
    /**
     * @var list<array{users: list<User>, payload: array{title: string, body: string, url?: string}}>
     */
    private array $pending = [];

    public function __construct(
        private readonly PushSender $sender,
    ) {
    }

    /**
     * @param list<User> $users
     * @param array{title: string, body: string, url?: string} $payload
     */
    public function queue(array $users, array $payload): void
    {
        if ([] === $users) {
            return;
        }

        $this->pending[] = ['users' => $users, 'payload' => $payload];
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $notification) {
            $this->sender->send($notification['users'], $notification['payload']);
        }
    }
}
