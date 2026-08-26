<?php

namespace App\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Les abonnements push de l'utilisateur connecté, pour l'écran « Mes appareils ».
 *
 * @implements ProviderInterface<PushSubscription>
 */
class UserPushSubscriptionsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PushSubscriptionRepository $repository,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->repository->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }
}
