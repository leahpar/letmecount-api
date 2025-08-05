<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/test')]
class TestController extends AbstractController
{
    #[Route('/')]
    public function index(
        #[CurrentUser] ?User $user
    ): Response
    {
        dump($user);
        return new JsonResponse(['user' => $user]);
    }

}
