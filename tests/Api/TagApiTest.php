<?php

namespace App\Tests\Api;

use App\Entity\Tag;
use App\Entity\Depense;
use App\Entity\Detail;
use DateTime;

class TagApiTest extends AuthenticatedApiTestCase
{
    public function testGetTagsCollection(): void
    {
        $this->call('GET', '/tags');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/ld+json; charset=utf-8');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('@context', $data);
        $this->assertEquals('/contexts/Tag', $data['@context']);
    }

    public function testCreateTag(): void
    {
        $tagData = [
            'libelle' => 'Restaurant'
        ];

        $this->call('POST', '/tags', [], $tagData);
        $this->assertResponseStatusCodeSame(201);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Restaurant', $data['libelle']);
    }



    public function testUpdateTag(): void
    {
        $tag = $this->createTestTag();

        $updatedData = [
            'libelle' => 'Loisirs et sorties'
        ];

        $this->call('PATCH', '/tags/' . $tag->id, [], $updatedData);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Loisirs et sorties', $data['libelle']);
    }

    public function testDeleteTag(): void
    {
        $tag = $this->createTestTag();

        $this->call('DELETE', '/tags/' . $tag->id);
        $this->assertResponseStatusCodeSame(204);

        $deletedTag = $this->em->getRepository(Tag::class)->find($tag->id);
        $this->assertNull($deletedTag);
    }

    public function testGetTag(): void
    {
        $tag = $this->createTestTag();

        $this->call('GET', '/tags/' . $tag->id);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals($tag->libelle, $data['libelle']);
    }

    public function testCreateDepenseWithTag(): void
    {
        $tag = $this->createTestTag();

        $depenseData = [
            'date' => '2024-01-15T00:00:00+00:00',
            'montant' => 50.00,
            'titre' => 'Restaurant avec tag',
            'partage' => 'parts',
            'payePar' => '/users/' . $this->user->id,
            'tag' => '/tags/' . $tag->id,
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
        $this->assertEquals('Restaurant avec tag', $data['titre']);
        $this->assertArrayHasKey('tag', $data);
        $this->assertEquals('/tags/' . $tag->id, $data['tag']);
    }

    public function testUpdateDepenseTag(): void
    {
        $tag1 = $this->createTestTag();
        $tag2 = $this->createTestTag('Transport');

        $depense = $this->createTestDepenseWithTag($tag1);

        $updatedData = [
            'date' => '2024-01-16T00:00:00+00:00',
            'montant' => 60.00,
            'titre' => 'Dépense mise à jour',
            'partage' => 'parts',
            'payePar' => '/users/' . $this->user->id,
            'tag' => '/tags/' . $tag2->id,
            'details' => [
                [
                    'user' => '/users/' . $this->user->id,
                    'parts' => 1,
                    'montant' => 60.00
                ]
            ]
        ];

        $this->call('PATCH', '/depenses/' . $depense->id, [], $updatedData);
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('/tags/' . $tag2->id, $data['tag']);
    }

    private function createTestTag(string $libelle = 'Restaurant'): Tag
    {
        $tag = new Tag();
        $tag->libelle = $libelle;

        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    private function createTestDepenseWithTag(Tag $tag): Depense
    {
        $depense = new Depense();
        $depense->date = new DateTime('2024-01-15');
        $depense->montant = 50.00;
        $depense->titre = 'Test Depense avec Tag';
        $depense->partage = 'parts';
        $depense->tag = $tag;
        $depense->payePar = $this->user;

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
