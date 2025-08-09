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
        $this->assertEquals('/contexts/Depense', $data['@context']);
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
                    'user' => '/users/' . $this->user->id,
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
                    'user' => '/users/' . $this->user->id,
                    'parts' => 1,
                    'montant' => 30.00 // Montant incorrect
                ]
            ]
        ];

        $this->call('POST', '/depenses', [], $depenseData);
        $this->assertResponseStatusCodeSame(422);
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
                    'user' => '/users/' . $this->user->id,
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

    public function testUserOnlySeesOwnDepenses(): void
    {
        // Créer une dépense pour l'utilisateur authentifié
        $depense1 = $this->createTestDepense();

        // Créer un autre utilisateur et une dépense pour lui
        $otherUser = $this->createUser('other@example.com', 'password', 'otheruser');
        $depense2 = new Depense();
        $depense2->date = new DateTime('2024-01-16');
        $depense2->montant = 75.00;
        $depense2->titre = 'Other User Depense';
        $depense2->partage = 'parts';

        $detail2 = new Detail();
        $detail2->user = $otherUser;
        $detail2->parts = 1;
        $detail2->montant = 75.00;
        $depense2->addDetail($detail2);

        $this->em->persist($depense2);
        $this->em->flush();

        // Récupérer toutes les dépenses - ne doit retourner que celles de l'utilisateur connecté
        $this->call('GET', '/depenses');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('member', $data);
        
        // Vérifier qu'on ne récupère que les dépenses de l'utilisateur authentifié
        $depensesTitres = array_column($data['member'], 'titre');
        $this->assertContains('Test Depense', $depensesTitres);
        $this->assertNotContains('Other User Depense', $depensesTitres);
        
        // Vérifier qu'on ne peut pas accéder à une dépense d'un autre utilisateur
        $this->call('GET', '/depenses/' . $depense2->id);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testFilterDepensesByTag(): void
    {
        // Créer des tags
        $tagRestaurant = $this->createTestTag('Restaurant');
        $tagTransport = $this->createTestTag('Transport');

        // Créer des dépenses avec différents tags
        $depense1 = $this->createTestDepenseWithTag($tagRestaurant, 'Dépense Restaurant');
        $depense2 = $this->createTestDepenseWithTag($tagTransport, 'Dépense Transport');
        $depense3 = $this->createTestDepense(); // Sans tag

        // Tester le filtre par tag restaurant
        $this->call('GET', '/depenses?tag=' . $tagRestaurant->id);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('member', $data);
        
        // Vérifier qu'on ne récupère que les dépenses avec le tag restaurant
        $depensesTitres = array_column($data['member'], 'titre');
        $this->assertContains('Dépense Restaurant', $depensesTitres);
        $this->assertNotContains('Dépense Transport', $depensesTitres);
        $this->assertNotContains('Test Depense', $depensesTitres);

        // Tester le filtre par tag transport
        $this->call('GET', '/depenses?tag=' . $tagTransport->id);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $depensesTitres = array_column($data['member'], 'titre');
        $this->assertContains('Dépense Transport', $depensesTitres);
        $this->assertNotContains('Dépense Restaurant', $depensesTitres);
        $this->assertNotContains('Test Depense', $depensesTitres);
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

    private function createTestTag(string $libelle): \App\Entity\Tag
    {
        $tag = new \App\Entity\Tag();
        $tag->libelle = $libelle;

        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    private function createTestDepenseWithTag(\App\Entity\Tag $tag, string $titre = 'Test Depense avec Tag'): Depense
    {
        $depense = new Depense();
        $depense->date = new DateTime('2024-01-15');
        $depense->montant = 50.00;
        $depense->titre = $titre;
        $depense->partage = 'parts';
        $depense->tag = $tag;

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
