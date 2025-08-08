<?php

namespace App\Tests\Api;

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
}
