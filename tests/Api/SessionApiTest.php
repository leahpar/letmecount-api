<?php

namespace App\Tests\Api;

use App\Entity\OAuthClient;
use App\Entity\RefreshToken;

/**
 * Les sessions telles que l'utilisateur les voit : une ligne nommée par appareil
 * ou par client MCP, et un moyen de les couper.
 */
class SessionApiTest extends AuthenticatedApiTestCase
{
    private const REDIRECT_URI = 'http://127.0.0.1:33418/callback';
    private const VERIFIER = 'un-code-verifier-de-longueur-suffisante-pour-rfc7636';

    /**
     * Ouvre une session MCP complète — consentement puis échange du code — et
     * rend les jetons émis.
     *
     * @return array<string, mixed>
     */
    private function openMcpSession(string $clientName = 'Claude'): array
    {
        $client = new OAuthClient();
        $client->clientId = bin2hex(random_bytes(8));
        $client->clientName = $clientName;
        $client->redirectUris = [self::REDIRECT_URI];
        $this->em->persist($client);
        $this->em->flush();

        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');

        $this->post('/authorize/consent', [
            'response_type' => 'code',
            'client_id' => $client->clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'approved' => true,
        ]);
        $this->assertResponseIsSuccessful();

        parse_str((string) parse_url($this->json()['redirect'], PHP_URL_QUERY), $query);

        $this->post('/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'client_id' => $client->clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ]);
        $this->assertResponseIsSuccessful();

        return $this->json();
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
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sessions(): array
    {
        $this->call('GET', '/sessions');
        $this->assertResponseIsSuccessful();

        return $this->json()['member'] ?? [];
    }

    public function testMcpSessionIsLabelledWithTheClientName(): void
    {
        $this->openMcpSession('Claude');

        $sessions = $this->sessions();

        $this->assertCount(1, $sessions);
        $this->assertSame('Claude', $sessions[0]['label']);
        $this->assertNotNull($sessions[0]['createdAt']);
        // Rien du jeton lui-même ne doit sortir.
        $this->assertArrayNotHasKey('refreshToken', $sessions[0]);
        $this->assertArrayNotHasKey('username', $sessions[0]);
    }

    /**
     * La rotation remplace le jeton à chaque renouvellement : sans héritage, la
     * session serait rebaptisée d'après le User-Agent du client dès le premier.
     */
    public function testLabelSurvivesRenewal(): void
    {
        $tokens = $this->openMcpSession('Claude');

        $this->post('/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $this->assertResponseIsSuccessful();

        $sessions = $this->sessions();

        // Une session, pas deux : le renouvellement remplace, il n'ajoute pas.
        $this->assertCount(1, $sessions);
        $this->assertSame('Claude', $sessions[0]['label']);
    }

    /**
     * Les sessions ouvertes avant l'introduction de la colonne n'ont pas de
     * libellé : elles en reçoivent un à leur prochain renouvellement, déduit du
     * User-Agent comme pour les passkeys.
     */
    public function testSessionWithoutLabelIsNamedAtItsNextRenewal(): void
    {
        $tokens = $this->openMcpSession('Claude');

        $token = $this->em->getRepository(RefreshToken::class)->findOneBy(['label' => 'Claude']);
        $token->label = null;
        $this->em->flush();

        $this->client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140.0');
        $this->post('/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->client->setServerParameter('HTTP_USER_AGENT', '');

        $this->assertSame('Chrome sur Linux', $this->sessions()[0]['label']);
    }

    public function testSessionsOfOtherUsersAreNeitherListedNorDeletable(): void
    {
        $this->openMcpSession();

        $autre = new RefreshToken();
        $autre->setRefreshToken(bin2hex(random_bytes(16)));
        $autre->setUsername('quelquun-dautre');
        $autre->setValid(new \DateTime('+1 year'));
        $autre->label = 'Le téléphone de quelqu\'un d\'autre';
        $this->em->persist($autre);
        $this->em->flush();

        $labels = array_column($this->sessions(), 'label');
        $this->assertNotContains('Le téléphone de quelqu\'un d\'autre', $labels);

        $this->call('DELETE', '/sessions/'.$autre->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Couper une session, c'est couper son renouvellement : le jeton d'accès
     * déjà émis vit sa dernière heure, mais plus rien ne le remplacera.
     */
    public function testDeletingASessionStopsItsRenewal(): void
    {
        $tokens = $this->openMcpSession();

        $this->call('DELETE', '/sessions/'.$this->sessions()[0]['id']);
        $this->assertResponseStatusCodeSame(204);

        $this->post('/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_grant', $this->json()['error']);
        $this->assertSame([], $this->sessions());
    }
}
