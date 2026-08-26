<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\DeviceNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Enregistre l'abonnement push du navigateur pour l'utilisateur connecté.
 *
 * Upsert sur l'endpoint : le front repousse son abonnement à chaque démarrage
 * (il n'existe pas de notification fiable de renouvellement côté navigateur), et
 * un même appareil peut changer de mains. Sans ça, un simple redémarrage de
 * l'app se solderait par une violation de contrainte unique.
 *
 * @implements ProcessorInterface<PushSubscription, PushSubscription>
 */
class PushSubscriptionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PushSubscriptionRepository $repository,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly DeviceNameResolver $deviceNameResolver,
    ) {}

    /**
     * @param PushSubscription $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PushSubscription
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $userAgent = $this->requestStack->getCurrentRequest()?->headers->get('User-Agent');
        $deviceName = $this->deviceNameResolver->resolve($userAgent, []);

        $subscription = $this->repository->findOneBy(['endpoint' => $data->endpoint]) ?? $data;

        $subscription->user = $user;
        $subscription->p256dh = $data->p256dh;
        $subscription->auth = $data->auth;
        $subscription->deviceName = $deviceName;

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }
}
