<?php

namespace App\Controller;

use App\Service\OAuth\ProtectedResourceMetadata;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Métadonnées OAuth publiques, celles qu'un client MCP lit avant même d'avoir
 * un jeton. Les deux routes sont donc en PUBLIC_ACCESS (security.yaml).
 *
 * Le serveur d'autorisation qu'elles annoncent, c'est nous : il publiera ses
 * propres métadonnées ici même au lot 3 (doc/couche-mcp.md).
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
}
