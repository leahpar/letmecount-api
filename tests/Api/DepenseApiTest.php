<?php

namespace App\Tests\Api;

use App\Entity\Depense;
use App\Entity\Detail;
use App\Entity\Tag;
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
        $tag = $this->createTag();

        $depenseData = [
            'date' => '2024-01-15T00:00:00+00:00',
            'montant' => 50.00,
            'titre' => 'Test Restaurant',
            'partage' => 'parts',
            'tag' => '/tags/' . $tag->id,
            'payePar' => '/users/' . $this->user->id,
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
        $this->assertArrayHasKey('payePar', $data);
        $this->assertEquals('/users/' . $this->user->id, $data['payePar']);
        $this->assertCount(1, $data['details']);
    }

    public function testCreateDepenseWithInvalidMontants(): void
    {
        $depenseData = [
            'date' => '2024-01-15T00:00:00+00:00',
            'montant' => 50.00,
            'titre' => 'Test Invalid',
            'partage' => 'parts',
            'tag' => '/tags/' . $this->createTag()->id,
            'payePar' => '/users/' . $this->user->id,
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
        $this->em->refresh($depense);
        $detail = $depense->details->first();

        $initialDepenseCount = count($this->em->getRepository(Depense::class)->findAll());

        $updatedData = [
            'date' => '2024-01-16T00:00:00+00:00',
            'montant' => 60.00,
            'titre' => 'Updated Restaurant',
            'partage' => 'montants',
            'tag' => '/tags/' . $depense->tag->id,
            'payePar' => '/users/' . $this->user->id,
            'details' => [
                [
                    'id' => $detail->id,
                    'user' => '/users/' . $this->user->id,
                    'parts' => 1,
                    'montant' => 60.00
                ]
            ]
        ];

        $this->call('PATCH', '/depenses/' . $depense->id, [], $updatedData);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Updated Restaurant', $data['titre']);
        $this->assertEquals(60.00, $data['montant']);
        $this->assertEquals('montants', $data['partage']);

        $finalDepenseCount = count($this->em->getRepository(Depense::class)->findAll());
        $this->assertEquals($initialDepenseCount, $finalDepenseCount, "Le nombre de dépenses ne devrait pas augmenter lors d'une mise à jour.");
    }

    public function testDeleteDepense(): void
    {
        $depense = $this->createTestDepense();
        // Doctrine remet l'identifiant à null sur l'objet supprimé : le retenir
        // avant l'appel, sinon le find() qui suit part sans identifiant.
        $id = $depense->id;

        $this->call('DELETE', '/depenses/' . $id);
        $this->assertResponseStatusCodeSame(204);

        // Vérifier que la dépense a été supprimée
        $deletedDepense = $this->em->getRepository(Depense::class)->find($id);
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
        $otherUser = $this->createUser('otheruser');
        $depense2 = new Depense();
        $depense2->date = new DateTime('2024-01-16');
        $depense2->montant = 75.00;
        $depense2->titre = 'Other User Depense';
        $depense2->partage = 'parts';
        $depense2->tag = $this->createTag();
        $depense2->payePar = $otherUser;

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
        $tagRestaurant = $this->createTag('Restaurant');
        $tagTransport = $this->createTag('Transport');

        // Créer des dépenses avec différents tags
        $depense1 = $this->createTestDepense($tagRestaurant, 'Dépense Restaurant');
        $depense2 = $this->createTestDepense($tagTransport, 'Dépense Transport');
        $depense3 = $this->createTestDepense(); // Sur un troisième tag

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

    private function createTestDepense(?Tag $tag = null, string $titre = 'Test Depense'): Depense
    {
        $depense = new Depense();
        $depense->date = new DateTime('2024-01-15');
        $depense->montant = 50.00;
        $depense->titre = $titre;
        $depense->partage = 'parts';
        $depense->payePar = $this->user;
        $depense->tag = $tag ?? $this->createTag();

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
