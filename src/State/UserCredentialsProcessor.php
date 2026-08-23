<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UpdateCredentialsDto;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Choix de son propre nom d'utilisateur.
 *
 * Depuis le passage à OAuth, cette opération est réservée à l'utilisateur
 * authentifié : la liaison du compte a déjà eu lieu et a émis un JWT.
 */
class UserCredentialsProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (!$data instanceof UpdateCredentialsDto) {
            throw new BadRequestHttpException('Données invalides');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('', 'Authentification requise');
        }

        if (!$data->username) {
            throw new BadRequestHttpException('Nom d\'utilisateur requis');
        }

        $existingUser = $this->userRepository->findOneBy(['username' => $data->username]);
        if ($existingUser && $existingUser->id !== $user->id) {
            throw new ConflictHttpException('Ce nom d\'utilisateur existe déjà');
        }

        $user->setUsername($data->username);
        $this->em->flush();
    }
}
