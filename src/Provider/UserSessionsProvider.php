<?php

namespace App\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Les sessions ouvertes de l'utilisateur connecté, pour l'écran « Mes appareils ».
 *
 * Sans dépôt dédié : le mapping de `RefreshToken` appartient au bundle, et lui
 * imposer une classe de dépôt pour un seul `findBy` reviendrait à s'y attacher
 * plus qu'il ne le demande.
 *
 * @implements ProviderInterface<RefreshToken>
 */
class UserSessionsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [];
        }

        return $this->em->getRepository(RefreshToken::class)->findBy(
            ['username' => $user->getUserIdentifier()],
            ['id' => 'DESC'],
        );
    }
}
