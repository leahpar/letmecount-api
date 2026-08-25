<?php

namespace App\Controller;

use App\Service\OAuth\AuthorizationServer\AuthorizationServerMetadata;
use App\Service\OAuth\ProtectedResourceMetadata;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Métadonnées OAuth publiques, celles qu'un client MCP lit avant même d'avoir
 * un jeton. Les routes sont donc en PUBLIC_ACCESS (security.yaml).
 *
 * Les deux documents sont les deux sauts de la découverte : la ressource
 * protégée désigne son serveur d'autorisation, qui décrit ensuite comment s'y
 * prendre. Chez nous les deux sont le même hôte (doc/couche-mcp.md §2).
 */
class OAuthMetadataController
{
    /**
     * RFC 9728. Deux routes pour un seul document : la forme canonique insère
     * le chemin de la ressource après le suffixe, et c'est celle qu'on annonce,
     * mais les clients essaient aussi la forme racine. Le `/mcp` final est le
     * chemin de l'endpoint, fixé dans config/packages/mcp.yaml.
     */
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'oauth_protected_resource_mcp', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource', name: 'oauth_protected_resource', methods: ['GET'])]
    public function protectedResource(ProtectedResourceMetadata $metadata): JsonResponse
    {
        return new JsonResponse($metadata->toArray());
    }

    /**
     * RFC 8414. Le chemin est fixe et sans variante : il n'y a qu'un serveur
     * d'autorisation, à la racine.
     */
    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_authorization_server', methods: ['GET'])]
    public function authorizationServer(AuthorizationServerMetadata $metadata): JsonResponse
    {
        return new JsonResponse($metadata->toArray());
    }
}
