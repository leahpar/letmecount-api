<?php

namespace App\Tests\Api;

class AuthTest extends AuthenticatedApiTestCase
{
    public function testAccessSecuredEndpointWithoutAuthentication(): void
    {
        $this->client->request('GET', '/api/users', [], [], ['ACCEPT' => 'application/json']);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAuthenticationAndAccessSecuredEndpoint(): void
    {
        $this->client->request('GET', '/api/users', [], [], ['ACCEPT' => 'application/json']);
        $this->assertResponseIsSuccessful();
    }
}
