<?php

namespace App\Tests\Api;

use App\Entity\User;

class DepenseTest extends AuthenticatedApiTestCase
{
    public function testCreateDepense(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'testuser']);

        $this->client->request(
            'POST',
            '/api/depenses',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'ACCEPT' => 'application/json'],
            json_encode([
                'titre' => 'Test Dépense',
                'montant' => 100.0,
                'date' => '2025-08-08',
                'partage' => 'parts',
                'details' => [
                    [
                        'user' => '/api/users/' . $user->id,
                        'montant' => 100.0,
                    ],
                ],
            ])
        );

        $this->assertResponseIsSuccessful();
    }
}
