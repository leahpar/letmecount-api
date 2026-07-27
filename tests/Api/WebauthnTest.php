<?php

namespace App\Tests\Api;

class WebauthnTest extends AuthenticatedApiTestCase
{
    public function testLoginOptionsArePublic(): void
    {
        $this->client->setServerParameter('HTTP_Authorization', '');

        $this->client->request(
            'POST',
            '/auth/webauthn/login/options',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'ACCEPT' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('challenge', $data);
        // Passkey découvrable : aucun credential n'est listé, l'appareil choisit
        $this->assertSame([], $data['allowCredentials'] ?? []);
    }

    /**
     * Le point critique : sans ça, n'importe qui pourrait enrôler un passkey
     * sur le compte d'un autre.
     */
    public function testRegisterOptionsRequireAuthentication(): void
    {
        $this->client->setServerParameter('HTTP_Authorization', '');

        $this->client->request(
            'POST',
            '/auth/webauthn/register/options',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'ACCEPT' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRegisterOptionsForAuthenticatedUser(): void
    {
        $this->client->request(
            'POST',
            '/auth/webauthn/register/options',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'ACCEPT' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('challenge', $data);
        $this->assertSame('testuser', $data['user']['name']);
        $this->assertSame('required', $data['authenticatorSelection']['residentKey']);
    }

    public function testListOwnPasskeys(): void
    {
        $this->call('GET', '/passkeys');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(0, $data['totalItems']);
    }

    public function testListPasskeysRequiresAuthentication(): void
    {
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->call('GET', '/passkeys');

        $this->assertResponseStatusCodeSame(401);
    }
}
