<?php

namespace App\Tests\Api;

class IntegrationTest extends AuthenticatedApiTestCase
{
    public function testUserSoldeCalculationWithMultipleDepenses(): void
    {
        // Créer un deuxième utilisateur
        $user2 = $this->createUser('user2');

        // Créer une première dépense où user1 paye 100 et chacun doit 50
        $depense1Data = [
            'date' => '2024-01-15T00:00:00+00:00',
            'montant' => 100.00,
            'titre' => 'Restaurant 1',
            'partage' => 'montants',
            'payePar' => '/users/' . $this->user->id,
            'details' => [
                [
                    'user' => '/users/' . $this->user->id,
                    'parts' => 1,
                    'montant' => 50.00 // user1 doit 50
                ],
                [
                    'user' => '/users/' . $user2->id,
                    'parts' => 1,
                    'montant' => 50.00 // user2 doit 50
                ]
            ]
        ];

        $this->call('POST', '/depenses', [], $depense1Data);
        $this->assertResponseStatusCodeSame(201);

        // Créer une deuxième dépense où user2 paye 60 et chacun doit 30
        $depense2Data = [
            'date' => '2024-01-16T00:00:00+00:00',
            'montant' => 60.00,
            'titre' => 'Restaurant 2',
            'partage' => 'montants',
            'payePar' => '/users/' . $user2->id,
            'details' => [
                [
                    'user' => '/users/' . $this->user->id,
                    'parts' => 1,
                    'montant' => 30.00 // user1 doit 30
                ],
                [
                    'user' => '/users/' . $user2->id,
                    'parts' => 1,
                    'montant' => 30.00 // user2 doit 30
                ]
            ]
        ];

        $this->call('POST', '/depenses', [], $depense2Data);
        $this->assertResponseStatusCodeSame(201);

        // Vérifier le solde de user1 : il a payé 100 et doit 50+30=80 → solde = 100-80 = +20
        $this->call('GET', '/users/' . $this->user->id);
        $this->assertResponseIsSuccessful();

        $user1Data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(20.00, $user1Data['solde']); // 100 - 50 - 30 = 20

        // Vérifier le solde de user2 : il a payé 60 et doit 50+30=80 → solde = 60-80 = -20
        $this->call('GET', '/users/' . $user2->id);
        $this->assertResponseIsSuccessful();

        $user2Data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(-20.00, $user2Data['solde']); // 60 - 50 - 30 = -20
    }

    public function testDepenseWithDetailsInResponse(): void
    {
        $user2 = $this->createUser('detailuser');

        $depenseData = [
            'date' => '2024-01-15T00:00:00+00:00',
            'montant' => 80.00,
            'titre' => 'Courses',
            'partage' => 'montants',
            'payePar' => '/users/' . $this->user->id,
            'details' => [
                [
                    'user' => '/users/' . $this->user->id,
                    'parts' => 1,
                    'montant' => 30.00
                ],
                [
                    'user' => '/users/' . $user2->id,
                    'parts' => 1,
                    'montant' => 50.00
                ]
            ]
        ];

        $this->call('POST', '/depenses', [], $depenseData);
        $this->assertResponseStatusCodeSame(201);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $depenseId = $responseData['id'];

        // Récupérer la dépense et vérifier que les détails sont inclus
        $this->call('GET', '/depenses/' . $depenseId);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Courses', $data['titre']);
        $this->assertEquals(80.00, $data['montant']);
        $this->assertArrayHasKey('details', $data);
        $this->assertCount(2, $data['details']);

        // Vérifier que chaque détail contient les bonnes informations
        $details = $data['details'];
        $this->assertArrayHasKey('user', $details[0]);
        $this->assertArrayHasKey('montant', $details[0]);
        $this->assertArrayHasKey('parts', $details[0]);
    }
}
