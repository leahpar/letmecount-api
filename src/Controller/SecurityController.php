<?php

namespace App\Controller;

use App\Service\OAuth\OAuthLoginService;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class SecurityController extends AbstractController
{
    /**
     * Connexion OAuth : le front a déjà obtenu un code d'autorisation chez le
     * provider, on l'échange ici côté serveur et on émet nos propres jetons.
     *
     * Le `link_token` n'est présent qu'à la première connexion, quand
     * l'utilisateur arrive par le lien d'invitation généré par l'admin.
     */
    // _format json : sans ça Symfony rend ses erreurs en HTML sur cette route,
    // et le front ne peut pas récupérer le message.
    #[Route('/auth/oauth', name: 'auth_oauth', methods: ['POST'], defaults: ['_format' => 'json'])]
    public function oauth(
        Request $request,
        OAuthLoginService $oauthLogin,
        AuthenticationSuccessHandler $authenticationSuccessHandler,
        RateLimiterFactoryInterface $authLinkLimiter,
    ): Response {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Corps de requête invalide');
        }

        $provider = $payload['provider'] ?? null;
        $code = $payload['code'] ?? null;
        $codeVerifier = $payload['code_verifier'] ?? null;
        $linkToken = $payload['link_token'] ?? null;

        if (!is_string($provider) || !is_string($code) || !is_string($codeVerifier)) {
            throw new BadRequestHttpException('Paramètres "provider", "code" et "code_verifier" requis');
        }

        $linkToken = is_string($linkToken) && '' !== $linkToken ? $linkToken : null;

        // Le jeton d'invitation ne fait que 6 chiffres : on borne le bruteforce par IP.
        // Une connexion normale (compte déjà lié) n'est pas concernée.
        if (null !== $linkToken) {
            $limit = $authLinkLimiter->create($request->getClientIp())->consume();
            if (!$limit->isAccepted()) {
                throw new TooManyRequestsHttpException(
                    $limit->getRetryAfter()->getTimestamp() - time(),
                    'Trop de tentatives, réessayez plus tard'
                );
            }
        }

        $user = $oauthLogin->login($provider, $code, $codeVerifier, $linkToken);

        return $authenticationSuccessHandler->handleAuthenticationSuccess($user);
    }
}
