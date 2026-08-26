<?php

namespace App\Service\OAuth\AuthorizationServer;

use App\Service\OAuth\ProtectedResourceMetadata;

/**
 * Le document RFC 8414, celui que le client lit après avoir appris de la
 * ressource protégée où est son serveur d'autorisation.
 *
 * Comme au lot 2, rien n'est saisi : tout se déduit de l'origine publiée par
 * `ProtectedResourceMetadata`, donc de `JWT_AUDIENCE`.
 */
final class AuthorizationServerMetadata
{
    public function __construct(
        private readonly ProtectedResourceMetadata $resource,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $issuer = $this->resource->authorizationServer();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/authorize',
            'token_endpoint' => $issuer.'/token',
            'registration_endpoint' => $issuer.'/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // Son absence fait refuser le serveur par tout client conforme, et
            // S256 est la seule méthode acceptée : `plain` est proscrit.
            'code_challenge_methods_supported' => ['S256'],
            // Clients publics : pas de secret à présenter au token endpoint.
            'token_endpoint_auth_methods_supported' => ['none'],
        ];
    }
}
