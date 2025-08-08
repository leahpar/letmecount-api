<?php

namespace App\Tests\Api;

class DepenseTest extends AuthenticatedApiTestCase
{
    public function testCreateDepense(): void
    {
        $this->call(
            'POST',
            '/depenses',
            null,
            [
                'titre' => 'Test Dépense',
                'montant' => 100.0,
                'date' => '2025-08-08',
                'partage' => 'montant',
                'details' => [
                    [
                        'user' => '/api/users/' . $this->user->id,
                        'parts' => 1,
                        'montant' => 100.0,
                    ],
                ],
            ]
        );

        $this->assertResponseIsSuccessful();
    }
}
