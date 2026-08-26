<?php

namespace App\Service\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Identité OAuth de la ressource protégée, au sens de la RFC 9728.
 *
 * Tout part de `JWT_AUDIENCE`, qui porte l'URI canonique du serveur MCP : c'est
 * à la fois l'audience de nos jetons (RFC 8707) et l'identifiant de ressource
 * publié dans les métadonnées. Un seul réglage, donc aucune dérive possible
 * entre ce qu'on émet et ce qu'on annonce.
 */
final class ProtectedResourceMetadata
{
    /** Origine de l'URI de ressource : scheme://host[:port]. */
    private readonly string $origin;

    /** Chemin de l'URI de ressource : vide, ou commençant par « / ». */
    private readonly string $path;

    public function __construct(
        #[Autowire('%env(JWT_AUDIENCE)%')]
        public readonly string $resource,
    ) {
        $parts = parse_url($resource);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(sprintf(
                'JWT_AUDIENCE doit porter l\'URI canonique du serveur MCP (ex. https://exemple.fr/mcp), reçu "%s".',
                $resource,
            ));
        }

        $this->origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $this->path = $parts['path'] ?? '';
    }

    /**
     * URL du document de métadonnées.
     *
     * RFC 9728 §3.1 : le suffixe `.well-known` s'insère *entre* l'hôte et le
     * chemin de la ressource — d'où le `/mcp` qui reste à la fin.
     */
    public function url(): string
    {
        return $this->origin.'/.well-known/oauth-protected-resource'.$this->path;
    }

    /**
     * Le serveur d'autorisation, c'est nous (doc/couche-mcp.md §3) : son
     * `issuer` est l'origine de l'API. Il ne publiera ses propres métadonnées
     * qu'au lot 3.
     */
    public function authorizationServer(): string
    {
        return $this->origin;
    }

    /**
     * Le document RFC 9728.
     *
     * Pas de `scopes_supported` : il n'y a qu'un seul niveau d'accès (M6), donc
     * rien à annoncer. Le champ est facultatif, et le déclarer vide dirait autre
     * chose — qu'aucun scope n'est acceptable.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'authorization_servers' => [$this->authorizationServer()],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Let-me-count',
        ];
    }
}
