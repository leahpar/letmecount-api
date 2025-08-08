<?php

namespace App\Tests\Api;

class AuthTest extends AuthenticatedApiTestCase
{

    public function testAuthenticationAndAccessSecuredEndpoint(): void
    {
        $this->call('GET', '/users');
        $this->assertResponseIsSuccessful();
    }
}
