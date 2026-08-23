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
     * Le claim `aud` est posé dès maintenant en prévision de la couche MCP,
     * qui devra valider l'audience (cf. doc/authentification-oauth.md).
     */
    public function testTokenCarriesExpectedAudience(): void
    {
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $payload = $jwtManager->parse($jwtManager->create($this->user));

        $this->assertContains('letmecount-api-test', (array) $payload['aud']);
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
