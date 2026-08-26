<?php

namespace App\Service\OAuth\AuthorizationServer;

use App\Entity\OAuthClient;
use App\Repository\OAuthClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * L'enregistrement dynamique de clients (RFC 7591).
 *
 * Ouvert, et ça n'est pas une négligence : l'enregistrement crée un `client_id`,
 * pas un accès (M4). Un client enregistré ne peut rien faire tant qu'un humain
 * déjà lié à un compte n'a pas donné son consentement sur `/authorize`. Le
 * limiteur de débit est posé sur le contrôleur, lui, parce qu'écrire une ligne
 * par requête anonyme mérite une borne — et le ménage ci-dessous fait le reste :
 * le limiteur borne le débit, pas le total.
 */
final class ClientRegistrar
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OAuthClientRepository $clients,
        // La durée de vie d'un refresh token borne aussi celle d'un client :
        // au-delà, plus aucune session ouverte depuis lui n'est renouvelable.
        #[Autowire('%gesdinet_jwt_refresh_token.ttl%')]
        private readonly int $staleAfter,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function register(array $metadata): OAuthClient
    {
        // Le ménage se fait ici, comme celui des codes expirés se fait à
        // l'émission du suivant : la table reste bornée sans tâche planifiée, et
        // l'endpoint qui la fait grossir est exactement celui qui la nettoie.
        $this->clients->deleteStale($this->staleAfter);

        $client = new OAuthClient();
        $client->clientId = bin2hex(random_bytes(16));
        $client->clientName = $this->clientName($metadata);
        $client->redirectUris = $this->redirectUris($metadata);

        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    /**
     * La réponse d'enregistrement (RFC 7591 §3.2.1). Pas de `client_secret` ni
     * de `registration_access_token` : il n'y a rien à gérer après coup.
     *
     * @return array<string, mixed>
     */
    public function toArray(OAuthClient $client): array
    {
        return [
            'client_id' => $client->clientId,
            'client_id_issued_at' => $client->createdAt->getTimestamp(),
            'client_name' => $client->clientName,
            'redirect_uris' => $client->redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function clientName(array $metadata): string
    {
        $name = $metadata['client_name'] ?? null;

        if (!is_string($name) || '' === trim($name)) {
            throw OAuthException::invalidClientMetadata('Métadonnée "client_name" requise.');
        }

        // Tronqué plutôt que refusé : le nom n'est qu'un libellé d'affichage, et
        // un client dont le nom est trop long n'a rien fait de mal.
        return mb_substr(trim($name), 0, 255);
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<string>
     */
    private function redirectUris(array $metadata): array
    {
        $uris = $metadata['redirect_uris'] ?? null;

        if (!is_array($uris) || [] === $uris) {
            throw OAuthException::invalidClientMetadata('Métadonnée "redirect_uris" requise, et non vide.');
        }

        if (\count($uris) > 10) {
            throw OAuthException::invalidClientMetadata('Trop d\'URI de redirection.');
        }

        $validated = [];

        foreach ($uris as $uri) {
            if (!is_string($uri)) {
                throw OAuthException::invalidClientMetadata('Les URI de redirection doivent être des chaînes.');
            }

            $validated[] = $this->redirectUri($uri);
        }

        return $validated;
    }

    /**
     * Les règles de l'OAuth 2.0 for Native Apps (RFC 8252) : une URI absolue,
     * sans fragment, et le clair réservé à la boucle locale — un client MCP en
     * ligne de commande écoute sur `http://127.0.0.1:port`, un client web est
     * en https, et un client mobile a son propre schéma.
     */
    private function redirectUri(string $uri): string
    {
        $parts = parse_url($uri);

        if (!is_array($parts) || !isset($parts['scheme']) || isset($parts['fragment'])) {
            throw OAuthException::invalidClientMetadata(sprintf('URI de redirection invalide : "%s".', $uri));
        }

        if (\strlen($uri) > 500) {
            throw OAuthException::invalidClientMetadata('URI de redirection trop longue.');
        }

        if ('http' === $parts['scheme'] && !in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]', '::1', 'localhost'], true)) {
            throw OAuthException::invalidClientMetadata(sprintf(
                'URI de redirection en clair hors boucle locale : "%s".',
                $uri,
            ));
        }

        return $uri;
    }
}
