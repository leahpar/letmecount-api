<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UpdateCredentialsDto;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserCredentialsProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (!$data instanceof UpdateCredentialsDto) {
            throw new BadRequestException('Données invalides');
        }

        if (!$data->token) {
            throw new BadRequestException('Token requis');
        }

        $user = $this->userRepository->findOneBy(['token' => $data->token]);
        if (!$user) {
            throw new UnauthorizedHttpException('', 'Token invalide');
        }

        if ($data->username !== null) {
            $existingUser = $this->userRepository->findOneBy(['username' => $data->username]);
            if ($existingUser && $existingUser->id !== $user->id) {
                throw new ConflictHttpException('Ce nom d\'utilisateur existe déjà');
            }

            $user->setUsername($data->username);
        }

        if ($data->password !== null) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $data->password);
            $user->setPassword($hashedPassword);
        }

        $user->setToken(null); // Invalidate the token after use
        $this->em->flush();
    }
}
