<?php

namespace App\Controller;

use App\Service\OAuth\AuthorizationServer\ClientRegistrar;
use App\Service\OAuth\AuthorizationServer\RequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enregistrement dynamique de clients (RFC 7591), l'étape 5–6 du flow.
 *
 * Public, parce qu'un client qui n'a pas encore de `client_id` n'a rien à
 * présenter. Ce que ça crée n'est pas un accès (M4), mais ça écrit une ligne :
 * d'où le limiteur.
 */
class OAuthRegistrationController
{
    #[Route('/register', name: 'oauth_register', methods: ['POST'])]
    public function __invoke(
        Request $request,
        ClientRegistrar $registrar,
        RateLimiterFactoryInterface $oauthRegisterLimiter,
    ): JsonResponse {
        $limit = $oauthRegisterLimiter->create($request->getClientIp())->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                $limit->getRetryAfter()->getTimestamp() - time(),
                'Trop d\'enregistrements, réessayez plus tard'
            );
        }

        $client = $registrar->register(RequestPayload::of($request));

        return new JsonResponse($registrar->toArray($client), 201, ['Cache-Control' => 'no-store']);
    }
}
