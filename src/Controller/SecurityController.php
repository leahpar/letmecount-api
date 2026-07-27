<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class SecurityController extends AbstractController
{
    #[Route('/auth/{token}', name: 'auth', requirements: ['token' => '\d{6}'])]
    public function auth(
        Request $request,
        EntityManagerInterface $em,
        string $token,
        JWTTokenManagerInterface $JWTManager,
        AuthenticationSuccessHandler $authenticationSuccessHandler,
        RateLimiterFactoryInterface $authCodeLimiter
    ): Response
    {
        // Le code ne fait que 6 chiffres : on limite le bruteforce par IP
        $limit = $authCodeLimiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                $limit->getRetryAfter()->getTimestamp() - time(),
                'Trop de tentatives, réessayez plus tard'
            );
        }

        $user = $em->getRepository(User::class)->findOneBy(['token' => $token]);
        if (!$user) {
            throw $this->createAccessDeniedException('Token invalide');
        }

        // Code valide : on rend ses tentatives à l'utilisateur
        $authCodeLimiter->create($request->getClientIp())->reset();

        $JWTManager->create($user);

        $user->setToken(null); // Invalidate the token after use
        $em->flush();

        return $authenticationSuccessHandler->handleAuthenticationSuccess($user);
    }
}
