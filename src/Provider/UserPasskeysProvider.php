<?php

namespace App\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Les passkeys de l'utilisateur connecté, pour l'écran « Mes appareils ».
 *
 * @implements ProviderInterface<WebauthnCredential>
 */
class UserPasskeysProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly WebauthnCredentialRepository $repository,
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
