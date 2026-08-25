<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\OAuth\AuthorizationServer\AuthorizationCodeStore;
use App\Service\OAuth\AuthorizationServer\AuthorizationRequestValidator;
use App\Service\OAuth\AuthorizationServer\OAuthException;
use App\Service\OAuth\AuthorizationServer\RequestPayload;
use App\Service\OAuth\AuthorizationServer\TokenIssuer;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'étape 8 : le code contre les jetons, puis les renouvellements.
 *
 * Public — les clients MCP n'ont pas de secret (M4) — et c'est PKCE qui fait le
 * travail d'authentification du client sur le grant `authorization_code`.
 */
class OAuthTokenController
{
    #[Route('/token', name: 'oauth_token', methods: ['POST'])]
    public function __invoke(
        Request $request,
        AuthorizationRequestValidator $validator,
        AuthorizationCodeStore $codes,
        TokenIssuer $issuer,
        RefreshTokenManagerInterface $refreshTokens,
        UserRepository $users,
    ): JsonResponse {
        $params = RequestPayload::of($request);
        $grantType = $params['grant_type'] ?? null;

        return match ($grantType) {
            'authorization_code' => $this->authorizationCode($params, $validator, $codes, $issuer),
            'refresh_token' => $this->refreshToken($params, $issuer, $refreshTokens, $users),
            default => throw new OAuthException(
                'unsupported_grant_type',
                'Seuls "authorization_code" et "refresh_token" sont acceptés.',
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function authorizationCode(
        array $params,
        AuthorizationRequestValidator $validator,
        AuthorizationCodeStore $codes,
        TokenIssuer $issuer,
    ): JsonResponse {
        $code = self::required($params, 'code');
        $clientId = self::required($params, 'client_id');
        $redirectUri = self::required($params, 'redirect_uri');
        $verifier = self::required($params, 'code_verifier');

        $user = $codes->consume($code, $clientId, $redirectUri, $verifier, $validator->resource($params));

        return $issuer->issue($user);
    }

    /**
     * Le renouvellement. La validation du jeton est refaite ici parce que le
     * firewall ne s'en charge que sur `/auth/refresh`, mais la **rotation**,
     * elle, reste celle de gesdinet : elle se déclenche sur l'émission, à partir
     * du `refresh_token` présent dans cette requête (cf. `TokenIssuer`).
     *
     * @param array<string, mixed> $params
     */
    private function refreshToken(
        array $params,
        TokenIssuer $issuer,
        RefreshTokenManagerInterface $refreshTokens,
        UserRepository $users,
    ): JsonResponse {
        $refreshToken = $refreshTokens->get(self::required($params, 'refresh_token'));

        if (null === $refreshToken || !$refreshToken->isValid()) {
            throw OAuthException::invalidGrant('Refresh token inconnu, expiré ou déjà utilisé.');
        }

        $user = $users->findOneBy(['username' => $refreshToken->getUsername()]);

        if (null === $user) {
            throw OAuthException::invalidGrant('Le compte associé à ce refresh token n\'existe plus.');
        }

        return $issuer->issue($user);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function required(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        if (!is_string($value) || '' === trim($value)) {
            throw OAuthException::invalidRequest(sprintf('Paramètre "%s" requis.', $key));
        }

        return trim($value);
    }
}
