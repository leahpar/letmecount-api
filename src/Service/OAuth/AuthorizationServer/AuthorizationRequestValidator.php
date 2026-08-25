<?php

namespace App\Service\OAuth\AuthorizationServer;

use App\Entity\OAuthClient;
use App\Repository\OAuthClientRepository;
use App\Service\OAuth\ProtectedResourceMetadata;

/**
 * Les contrôles de la requête d'autorisation, partagés par `GET /authorize` et
 * par `POST /authorize/consent`.
 *
 * Ils sont volontairement en trois temps, parce que la RFC 6749 §4.1.2.1 traite
 * les erreurs différemment selon le moment : tant que le client et son URI de
 * redirection ne sont pas établis, **on ne redirige pas** — rediriger une erreur
 * vers une URI non vérifiée, c'est offrir une redirection ouverte. Une fois
 * qu'ils le sont, les erreurs suivantes repartent vers le client, qui est le
 * seul à savoir quoi en faire.
 */
final class AuthorizationRequestValidator
{
    /** Longueurs imposées par la RFC 7636 §4.1 au `code_verifier`, donc au challenge S256. */
    private const CHALLENGE_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    public function __construct(
        private readonly OAuthClientRepository $clients,
        private readonly ProtectedResourceMetadata $resource,
    ) {
    }

    /**
     * Premier temps : qui demande ? Une erreur ici se rend à l'humain.
     *
     * @param array<string, mixed> $params
     */
    public function client(array $params): OAuthClient
    {
        $clientId = self::string($params, 'client_id');

        if (null === $clientId) {
            throw OAuthException::invalidRequest('Paramètre "client_id" requis.');
        }

        $client = $this->clients->findOneByClientId($clientId);

        if (null === $client) {
            throw OAuthException::invalidClient('Client inconnu. Enregistrez-le sur /register.');
        }

        return $client;
    }

    /**
     * Deuxième temps : l'URI de retour est-elle bien la sienne ? Comparaison
     * **exacte** — c'est elle qui empêche un client malveillant de se réclamer
     * du `client_id` d'un autre pour se faire livrer le code.
     *
     * @param array<string, mixed> $params
     */
    public function redirectUri(OAuthClient $client, array $params): string
    {
        $redirectUri = self::string($params, 'redirect_uri');

        if (null === $redirectUri) {
            throw OAuthException::invalidRequest('Paramètre "redirect_uri" requis.');
        }

        if (!in_array($redirectUri, $client->redirectUris, true)) {
            throw OAuthException::invalidRequest('L\'URI de redirection n\'est pas enregistrée pour ce client.');
        }

        return $redirectUri;
    }

    /**
     * Troisième temps : le reste. À partir d'ici, les erreurs peuvent repartir
     * vers le client.
     *
     * @param array<string, mixed> $params
     */
    public function request(OAuthClient $client, string $redirectUri, array $params): AuthorizationRequest
    {
        if ('code' !== self::string($params, 'response_type')) {
            throw new OAuthException('unsupported_response_type', 'Seul le type de réponse "code" est accepté.');
        }

        // PKCE est obligatoire, et S256 la seule méthode : `plain` ne protège de
        // rien quand le canal de redirection est celui qu'on cherche à protéger.
        if ('S256' !== self::string($params, 'code_challenge_method')) {
            throw OAuthException::invalidRequest('Le paramètre "code_challenge_method" doit valoir "S256".');
        }

        $challenge = self::string($params, 'code_challenge');

        if (null === $challenge || 1 !== preg_match(self::CHALLENGE_PATTERN, $challenge)) {
            throw OAuthException::invalidRequest('Paramètre "code_challenge" absent ou malformé.');
        }

        $state = self::string($params, 'state');

        if (null !== $state && \strlen($state) > 512) {
            throw OAuthException::invalidRequest('Paramètre "state" trop long.');
        }

        return new AuthorizationRequest(
            $client,
            $redirectUri,
            $challenge,
            $state,
            $this->resource($params),
        );
    }

    /**
     * L'indicateur de ressource (RFC 8707), commun à `/authorize` et `/token`.
     *
     * Absent, il est accepté : il n'y a qu'une ressource, donc rien d'ambigu à
     * lever (M6). Erroné, il est refusé — le client demande alors un jeton pour
     * quelqu'un d'autre, et lui en remettre un pour nous serait le tromper.
     *
     * @param array<string, mixed> $params
     */
    public function resource(array $params): ?string
    {
        $resource = self::string($params, 'resource');

        if (null === $resource) {
            return null;
        }

        if ($resource !== $this->resource->resource) {
            throw OAuthException::invalidTarget(sprintf(
                'Ressource inconnue. La seule ressource protégée est "%s".',
                $this->resource->resource,
            ));
        }

        return $resource;
    }

    /**
     * Une chaîne non vide, ou null. Les clients envoient volontiers des
     * paramètres vides plutôt que pas de paramètre du tout.
     *
     * @param array<string, mixed> $params
     */
    private static function string(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
