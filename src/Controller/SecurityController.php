<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SecurityController extends AbstractController
{
    #[Route('/auth/{token}', name: 'auth')]
    public function auth(
        EntityManagerInterface $em,
        string $token,
        JWTTokenManagerInterface $JWTManager,
        AuthenticationSuccessHandler $authenticationSuccessHandler
    ): Response
    {
        $user = $em->getRepository(User::class)->findOneBy(['token' => $token]);
        if (!$user) {
            throw $this->createAccessDeniedException('Token invalide');
        }

        $JWTManager->create($user);
        return $authenticationSuccessHandler->handleAuthenticationSuccess($user);
    }
}
