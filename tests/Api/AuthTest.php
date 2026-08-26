<?php

namespace App\Tests\Api;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

class AuthTest extends AuthenticatedApiTestCase
{
    public function testAuthenticationAndAccessSecuredEndpoint(): void
    {
        $this->call('GET', '/users');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('@context', $data);
        $this->assertEquals('/contexts/User', $data['@context']);
    }

    /**
     * L'audience émise est l'URI canonique du serveur MCP, seule valeur que le
     * spec accepte (RFC 8707, cf. doc/couche-mcp.md §3).
     */
    public function testTokenCarriesExpectedAudience(): void
    {
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $payload = $jwtManager->parse($jwtManager->create($this->user));

        $this->assertContains('http://localhost/mcp', (array) $payload['aud']);
    }

    /**
     * Propriété de déploiement, la même que ci-dessous d'un cran plus tard : les
     * jetons portant l'ancienne audience opaque restent acceptés le temps que le
     * parc tourne, sinon changer la valeur déconnecte tout le monde.
     */
    public function testTokenWithLegacyAudienceIsStillAccepted(): void
    {
        $encoder = static::getContainer()->get('lexik_jwt_authentication.encoder');
        $token = $encoder->encode([
            'username' => $this->user->getUserIdentifier(),
            'roles' => $this->user->getRoles(),
            'exp' => time() + 3600,
            'aud' => 'letmecount-api-test',
        ]);

        $this->client->setServerParameter('HTTP_Authorization', sprintf('Bearer %s', $token));
        $this->call('GET', '/users');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Propriété de déploiement : les jetons émis avant l'introduction du claim
     * n'ont pas d'`aud` et doivent rester valides, sinon la mise en production
     * déconnecte tout le monde.
     */
    public function testTokenWithoutAudienceIsStillAccepted(): void
    {
        $encoder = static::getContainer()->get('lexik_jwt_authentication.encoder');
        $token = $encoder->encode([
            'username' => $this->user->getUserIdentifier(),
            'roles' => $this->user->getRoles(),
            'exp' => time() + 3600,
        ]);

        $this->client->setServerParameter('HTTP_Authorization', sprintf('Bearer %s', $token));
        $this->call('GET', '/users');

        $this->assertResponseIsSuccessful();
    }

    public function testTokenWithWrongAudienceIsRejected(): void
    {
        // On passe par l'encodeur : le manager déclencherait JWT_CREATED, qui
        // réécrirait justement l'audience qu'on cherche à falsifier ici.
        $encoder = static::getContainer()->get('lexik_jwt_authentication.encoder');
        $token = $encoder->encode([
            'username' => $this->user->getUserIdentifier(),
            'roles' => $this->user->getRoles(),
            'exp' => time() + 3600,
            'aud' => 'une-autre-audience',
        ]);

        $this->client->setServerParameter('HTTP_Authorization', sprintf('Bearer %s', $token));
        $this->call('GET', '/users');

        $this->assertResponseStatusCodeSame(401);
    }
}
