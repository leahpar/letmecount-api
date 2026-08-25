<?php

namespace App\Tests\Api;

use App\Entity\OAuthClient;

/**
 * Le serveur d'autorisation : enregistrement, consentement, échange du code.
 *
 * Le parcours nominal est joué en entier une fois, puis chaque contrôle est
 * pris à part — c'est là que les erreurs de ce genre de code se cachent, pas
 * dans le chemin heureux.
 */
class OAuthAuthorizationServerTest extends AuthenticatedApiTestCase
{
    private const REDIRECT_URI = 'http://127.0.0.1:33418/callback';
    private const VERIFIER = 'un-code-verifier-de-longueur-suffisante-pour-rfc7636';

    protected function setUp(): void
    {
        parent::setUp();

        // Le limiteur de /register garde son état en cache, donc d'une exécution
        // à l'autre : sans remise à zéro, ce sont l'ordre et la répétition des
        // lancements qui décideraient du résultat.
        static::getContainer()->get('limiter.oauth_register')->create('127.0.0.1')->reset();
    }

    private static function challenge(string $verifier = self::VERIFIER): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function authorizationParams(OAuthClient $client, array $overrides = []): array
    {
        return $overrides + [
            'response_type' => 'code',
            'client_id' => $client->clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => self::challenge(),
            'code_challenge_method' => 'S256',
            'state' => 'etat-du-client',
            'resource' => 'http://localhost/mcp',
        ];
    }

    private function registerClient(string $name = 'Client de test'): OAuthClient
    {
        $client = new OAuthClient();
        $client->clientId = bin2hex(random_bytes(8));
        $client->clientName = $name;
        $client->redirectUris = [self::REDIRECT_URI];

        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function post(string $uri, array $params): void
    {
        $this->client->request(
            'POST',
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($params)
        );
    }

    /**
     * Extrait un paramètre de la query d'une URL de redirection.
     */
    private static function param(string $url, string $key): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return is_string($query[$key] ?? null) ? $query[$key] : null;
    }

    // --- Métadonnées ---------------------------------------------------------

    public function testAuthorizationServerMetadataIsPublicAndComplete(): void
    {
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->client->request('GET', '/.well-known/oauth-authorization-server');

        $this->assertResponseIsSuccessful();
        $metadata = $this->json();

        $this->assertSame('http://localhost', $metadata['issuer']);
        $this->assertSame('http://localhost/authorize', $metadata['authorization_endpoint']);
        $this->assertSame('http://localhost/token', $metadata['token_endpoint']);
        $this->assertSame('http://localhost/register', $metadata['registration_endpoint']);
        // Son absence fait refuser le serveur par tout client conforme.
        $this->assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        $this->assertSame(['authorization_code', 'refresh_token'], $metadata['grant_types_supported']);
    }

    // --- Parcours nominal ----------------------------------------------------

    public function testFullAuthorizationCodeFlow(): void
    {
        // 5–6. Enregistrement : le client n'a pas encore d'identité.
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT_URI],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $registration = $this->json();
        $this->assertArrayNotHasKey('client_secret', $registration);
        $clientId = $registration['client_id'];

        // 7. Demande d'autorisation : le navigateur arrive sans jeton et repart
        // vers l'écran de consentement du front, paramètres en main.
        $this->client->request('GET', '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => self::challenge(),
            'code_challenge_method' => 'S256',
            'state' => 'etat-du-client',
            'resource' => 'http://localhost/mcp',
        ]));

        $this->assertResponseStatusCodeSame(302);
        $consentUrl = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith('http://localhost:5173/oauth/consent?', $consentUrl);
        $this->assertSame('Claude', self::param($consentUrl, 'client_name'));
        $this->assertSame('etat-du-client', self::param($consentUrl, 'state'));

        // Le front se connecte — ici, le Bearer de la classe parente — et POSTe
        // ce qu'il a reçu.
        parse_str((string) parse_url($consentUrl, PHP_URL_QUERY), $consentParams);
        $this->loginUser('testuser');
        $this->post('/authorize/consent', $consentParams + ['approved' => true]);

        $this->assertResponseIsSuccessful();
        $redirect = $this->json()['redirect'];
        $this->assertStringStartsWith(self::REDIRECT_URI.'?', $redirect);
        $this->assertSame('etat-du-client', self::param($redirect, 'state'));

        $code = self::param($redirect, 'code');
        $this->assertNotNull($code);

        // 8. Échange du code. Le token endpoint est public : le client n'a pas
        // de secret, c'est PKCE qui l'authentifie.
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
            'resource' => 'http://localhost/mcp',
        ]);

        $this->assertResponseIsSuccessful();
        $tokens = $this->json();
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertGreaterThan(0, $tokens['expires_in']);
        $this->assertArrayHasKey('refresh_token', $tokens);

        // 9. Le jeton ouvre bien l'endpoint MCP, ce qui est tout le but.
        $this->client->setServerParameter('HTTP_Authorization', 'Bearer '.$tokens['access_token']);
        $this->call('GET', '/users');
        $this->assertResponseIsSuccessful();

        // Et le refresh rendu par /token se renouvelle au même endroit.
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
        ]);

        $this->assertResponseIsSuccessful();
        $renewed = $this->json();
        $this->assertSame('Bearer', $renewed['token_type']);
        // single_use : le jeton présenté est détruit et remplacé.
        $this->assertNotSame($tokens['refresh_token'], $renewed['refresh_token']);
    }

    // --- Enregistrement ------------------------------------------------------

    public function testRegistrationRefusesClearTextRedirectOutsideLoopback(): void
    {
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/register', [
            'client_name' => 'Client douteux',
            'redirect_uris' => ['http://exemple.fr/callback'],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_client_metadata', $this->json()['error']);
    }

    public function testRegistrationRequiresRedirectUris(): void
    {
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/register', ['client_name' => 'Sans retour']);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_client_metadata', $this->json()['error']);
    }

    // --- /authorize ----------------------------------------------------------

    public function testUnknownClientIsRefusedWithoutRedirecting(): void
    {
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->client->request('GET', '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => 'client-qui-nexiste-pas',
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => self::challenge(),
            'code_challenge_method' => 'S256',
        ]));

        // Surtout pas de 302 : rediriger vers une URI non vérifiée offrirait
        // une redirection ouverte (RFC 6749 §4.1.2.1).
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_client', $this->json()['error']);
    }

    public function testUnregisteredRedirectUriIsRefusedWithoutRedirecting(): void
    {
        $client = $this->registerClient();

        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->client->request('GET', '/authorize?'.http_build_query(
            $this->authorizationParams($client, ['redirect_uri' => 'http://127.0.0.1:1/pirate'])
        ));

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_request', $this->json()['error']);
    }

    /**
     * Une fois le client et son URI établis, l'erreur repart vers le client :
     * c'est lui qui sait quoi en faire.
     */
    public function testInvalidChallengeMethodIsReportedToTheClient(): void
    {
        $client = $this->registerClient();

        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->client->request('GET', '/authorize?'.http_build_query(
            $this->authorizationParams($client, ['code_challenge_method' => 'plain'])
        ));

        $this->assertResponseStatusCodeSame(302);
        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        $this->assertSame('invalid_request', self::param($location, 'error'));
        $this->assertSame('etat-du-client', self::param($location, 'state'));
    }

    public function testWrongResourceIsRefused(): void
    {
        $client = $this->registerClient();

        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->client->request('GET', '/authorize?'.http_build_query(
            $this->authorizationParams($client, ['resource' => 'https://ailleurs.example/mcp'])
        ));

        $this->assertResponseStatusCodeSame(302);
        $this->assertSame('invalid_target', self::param(
            (string) $this->client->getResponse()->headers->get('Location'),
            'error'
        ));
    }

    public function testConsentRequiresAuthentication(): void
    {
        $client = $this->registerClient();

        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/authorize/consent', $this->authorizationParams($client) + ['approved' => true]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRefusedConsentSendsAccessDeniedBackToTheClient(): void
    {
        $client = $this->registerClient();

        $this->post('/authorize/consent', $this->authorizationParams($client) + ['approved' => false]);

        $this->assertResponseIsSuccessful();
        $redirect = $this->json()['redirect'];
        $this->assertSame('access_denied', self::param($redirect, 'error'));
        $this->assertSame('etat-du-client', self::param($redirect, 'state'));
        $this->assertNull(self::param($redirect, 'code'));
    }

    // --- /token --------------------------------------------------------------

    /**
     * Émet un code d'autorisation valide et le rend.
     */
    private function issueCode(OAuthClient $client): string
    {
        $this->loginUser('testuser');
        $this->post('/authorize/consent', $this->authorizationParams($client) + ['approved' => true]);
        $this->assertResponseIsSuccessful();

        return (string) self::param($this->json()['redirect'], 'code');
    }

    public function testWrongCodeVerifierIsRefused(): void
    {
        $client = $this->registerClient();
        $code = $this->issueCode($client);

        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client->clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => 'un-autre-verifier-tout-aussi-long-mais-different',
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_grant', $this->json()['error']);
    }

    public function testReplayedCodeIsRefused(): void
    {
        $client = $this->registerClient();
        $code = $this->issueCode($client);

        $exchange = fn () => $this->post('/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client->clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ]);

        $this->client->setServerParameter('HTTP_Authorization', '');
        $exchange();
        $this->assertResponseIsSuccessful();

        $exchange();
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_grant', $this->json()['error']);
    }

    /**
     * Un code émis pour une URI ne s'échange pas depuis une autre, même
     * enregistrée : c'est ce qui empêche un code intercepté de servir ailleurs.
     */
    public function testCodeIsBoundToItsRedirectUri(): void
    {
        $client = $this->registerClient();
        $client->redirectUris = [self::REDIRECT_URI, 'http://127.0.0.1:9999/autre'];
        $this->em->flush();

        $code = $this->issueCode($client);

        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client->clientId,
            'redirect_uri' => 'http://127.0.0.1:9999/autre',
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_grant', $this->json()['error']);
    }

    public function testUnsupportedGrantTypeIsRefused(): void
    {
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/token', ['grant_type' => 'password', 'username' => 'x', 'password' => 'y']);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('unsupported_grant_type', $this->json()['error']);
    }

    /**
     * Le token endpoint doit accepter le formulaire : c'est ce que la RFC 6749
     * impose, et donc ce que les clients envoient.
     */
    public function testTokenEndpointAcceptsFormEncodedBody(): void
    {
        $client = $this->registerClient();
        $code = $this->issueCode($client);

        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client->clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('access_token', $this->json());
    }

    /**
     * Le jeton émis par le flow OAuth est le même objet que celui du web (M6) :
     * même audience, donc utilisable sur /mcp.
     */
    public function testIssuedTokenCarriesTheCanonicalAudience(): void
    {
        $client = $this->registerClient();
        $code = $this->issueCode($client);

        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->post('/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $client->clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ]);

        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $payload = $jwtManager->parse($this->json()['access_token']);

        $this->assertContains('http://localhost/mcp', (array) $payload['aud']);
    }
}
