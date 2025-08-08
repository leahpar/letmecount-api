<?php

namespace App\Tests\Api;

use App\Entity\Depense;
use App\Entity\Detail;
use DateTime;

class DepenseApiTest extends AuthenticatedApiTestCase
{
    public function testGetDepensesCollection(): void
    {
        $this->call('GET', '/depenses');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/ld+json; charset=utf-8');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('@context', $data);
        $this->assertEquals('/api/contexts/Depense', $data['@context']);
    }

    public function testCreateDepense(): void
    {
        $depenseData = [
            'date' => '2024-01-15T00:00:00+00:00',
            'montant' => 50.00,
            'titre' => 'Test Restaurant',
            'partage' => 'parts',
            'details' => [
                [
                    'user' => '/api/users/' . $this->user->id,
                    'parts' => 1,
                    'montant' => 50.00
                ]
            ]
        ];

        $this->call('POST', '/depenses', [], $depenseData);
        $this->assertResponseStatusCodeSame(201);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Test Restaurant', $data['titre']);
        $this->assertEquals(50.00, $data['montant']);
        $this->assertEquals('parts', $data['partage']);
        $this->assertCount(1, $data['details']);
    }

    public function testCreateDepenseWithInvalidMontants(): void
    {
        $depenseData = [
            'date' => '2024-01-15T00:00:00+00:00',
            'montant' => 50.00,
            'titre' => 'Test Invalid',
            'partage' => 'parts',
            'details' => [
                [
                    'user' => '/api/users/' . $this->user->id,
                    'parts' => 1,
                    'montant' => 30.00 // Montant incorrect
                ]
            ]
        ];

        $this->call('POST', '/depenses', [], $depenseData);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateDepense(): void
    {
        // Créer une dépense d'abord
        $depense = $this->createTestDepense();

        $updatedData = [
            'date' => '2024-01-16T00:00:00+00:00',
            'montant' => 60.00,
            'titre' => 'Updated Restaurant',
            'partage' => 'montants',
            'details' => [
                [
                    'user' => '/api/users/' . $this->user->id,
                    'parts' => 1,
                    'montant' => 60.00
                ]
            ]
        ];

        $this->call('PUT', '/depenses/' . $depense->id, [], $updatedData);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Updated Restaurant', $data['titre']);
        $this->assertEquals(60.00, $data['montant']);
        $this->assertEquals('montants', $data['partage']);
    }

    public function testDeleteDepense(): void
    {
        $depense = $this->createTestDepense();

        $this->call('DELETE', '/depenses/' . $depense->id);
        $this->assertResponseStatusCodeSame(204);

        // Vérifier que la dépense a été supprimée
        $deletedDepense = $this->em->getRepository(Depense::class)->find($depense->id);
        $this->assertNull($deletedDepense);
    }

    public function testGetDepense(): void
    {
        $depense = $this->createTestDepense();

        $this->call('GET', '/depenses/' . $depense->id);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals($depense->titre, $data['titre']);
        $this->assertArrayHasKey('details', $data);
        $this->assertCount(1, $data['details']);
    }

    private function createTestDepense(): Depense
    {
        $depense = new Depense();
        $depense->date = new DateTime('2024-01-15');
        $depense->montant = 50.00;
        $depense->titre = 'Test Depense';
        $depense->partage = 'parts';

        $detail = new Detail();
        $detail->user = $this->user;
        $detail->parts = 1;
        $detail->montant = 50.00;

        $depense->addDetail($detail);

        $this->em->persist($depense);
        $this->em->flush();

        return $depense;
    }
}
