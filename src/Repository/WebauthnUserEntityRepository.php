<?php

namespace App\Repository;

use App\Entity\User;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Fait le pont entre nos User et les entités utilisateur de WebAuthn.
 * Le userHandle est l'id numérique : stable même si le username change.
 */
readonly class WebauthnUserEntityRepository implements PublicKeyCredentialUserEntityRepositoryInterface
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity
    {
        $user = $this->userRepository->findOneBy(['username' => $username]);

        return $user ? $this->toUserEntity($user) : null;
    }

    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity
    {
        $user = $this->userRepository->find((int) $userHandle);

        return $user ? $this->toUserEntity($user) : null;
    }

    private function toUserEntity(User $user): PublicKeyCredentialUserEntity
    {
        return PublicKeyCredentialUserEntity::create(
            (string) $user->getUsername(),
            (string) $user->id,
            (string) $user->getUsername(),
        );
    }
}
