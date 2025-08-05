<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\UserSearchInputDTO;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/users')]
class UserController extends AbstractController
{
    #[Route('', methods: ['GET'])]
    public function lister(
        #[MapQueryString] UserSearchInputDTO $searchInput,
        EntityManagerInterface               $em,
    ): Response
    {

        $users = $em->getRepository(User::class)->findBySearchInput($searchInput);
        return new JsonResponse($users);
    }
}
