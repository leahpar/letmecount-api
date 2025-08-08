<?php

namespace App\Tests\Api;

class UserApiTest extends AuthenticatedApiTestCase
{
    public function testGetUsersCollection(): void
    {
        $this->call('GET', '/users');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/ld+json; charset=utf-8');
        
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('hydra:member', $data);
        $this->assertIsArray($data['hydra:member']);
    }

    public function testGetUser(): void
    {
        $this->call('GET', '/users/' . $this->user->id);
        $this->assertResponseIsSuccessful();
        
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals($this->user->getUsername(), $data['username']);
        $this->assertArrayHasKey('solde', $data);
        $this->assertEquals(0.0, $data['solde']);
    }

    public function testSearchUsersByUsername(): void
    {
        // Créer un autre utilisateur pour le test
        $this->createUser('searchuser');
        
        $this->call('GET', '/users?username=search');
        $this->assertResponseIsSuccessful();
        
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('hydra:member', $data);
        $users = $data['hydra:member'];
        $this->assertCount(1, $users);
        $this->assertEquals('searchuser', $users[0]['username']);
    }
}