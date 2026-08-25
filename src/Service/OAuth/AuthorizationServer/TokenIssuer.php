<?php

namespace App\Service\OAuth\AuthorizationServer;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Le point unique d'émission des jetons — la couture que le reste du serveur
 * d'autorisation ne franchit pas (doc/couche-mcp.md §6).
 *
 * Il n'émet rien lui-même : il appelle `handleAuthenticationSuccess`, celui-là
 * même dont se servent déjà la connexion OAuth, le passkey et le refresh, et se
 * contente de renommer les clés au format OAuth. Un jeton MCP est donc **le même
 * objet** qu'un jeton web (M6) : même audience, même durée, même révocation.
 *
 * Effet de bord voulu : le listener de gesdinet accroché à cet appel lit le
 * `refresh_token` de la requête courante. Sur `/token` en `refresh_token`, il
 * fait donc la rotation habituelle — famille conservée, ancien jeton détruit —
 * sans que rien n'ait à être réécrit ici.
 */
final class TokenIssuer
{
    public function __construct(
        private readonly AuthenticationSuccessHandler $successHandler,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {
    }

    public function issue(User $user): JsonResponse
    {
        $data = json_decode((string) $this->successHandler->handleAuthenticationSuccess($user)->getContent(), true);

        if (!is_array($data) || !isset($data['token']) || !is_string($data['token'])) {
            throw new \LogicException('L\'émission des jetons n\'a pas rendu de jeton.');
        }

        $payload = $this->jwtManager->parse($data['token']);
        $expiresAt = is_int($payload['exp'] ?? null) ? $payload['exp'] : time();

        $body = [
            'access_token' => $data['token'],
            'token_type' => 'Bearer',
            'expires_in' => max(0, $expiresAt - time()),
        ];

        // Toujours présent en pratique — gesdinet est branché sur l'événement de
        // succès — mais l'émission ne dépend pas de lui, et le format OAuth fait
        // du refresh token un champ facultatif.
        if (isset($data['refresh_token']) && is_string($data['refresh_token'])) {
            $body['refresh_token'] = $data['refresh_token'];
        }

        // RFC 6749 §5.1 : une réponse porteuse de jetons ne se met pas en cache.
        return new JsonResponse($body, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
