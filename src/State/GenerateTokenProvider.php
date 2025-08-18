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
        private UserRepository $userRepository,
        private EntityManagerInterface $em
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

        // Générer un token unique
        $token = bin2hex(random_bytes(32));
        $user->setToken($token);
        
        $this->em->flush();

        return [
            'token' => $token,
            'user_id' => $user->id,
            'username' => $user->getUsername(),
            'message' => 'Token généré avec succès'
        ];
    }
}