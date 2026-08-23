<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GenerateTokenProvider implements ProviderInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $userId = $uriVariables['id'] ?? null;

        if (!$userId) {
            throw new NotFoundHttpException('Utilisateur non trouvé');
        }

        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new NotFoundHttpException('Utilisateur non trouvé');
        }

        // Générer un jeton de liaison unique (6 caractères numériques)
        $user->setToken((string) random_int(100000, 999999));

        $this->em->flush();

        return $user;
    }
}
