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
        $this->assertArrayHasKey('@context', $data);
        $this->assertEquals('/contexts/User', $data['@context']);
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
        $this->assertArrayHasKey('@context', $data);
        $this->assertEquals('/contexts/User', $data['@context']);

        $users = $data['member'];
        $this->assertCount(1, $users);
        $this->assertEquals('searchuser', $users[0]['username']);
    }

    public function testCreateUserWithoutAdminRole(): void
    {
        $userData = [
            'username' => 'newuser',
            'password' => 'password123',
            'roles' => ['ROLE_USER']
        ];

        $this->call('POST', '/users', [], $userData);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateUserWithAdminRole(): void
    {
        // Donner le rôle ADMIN à l'utilisateur de test
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->em->flush();

        $userData = [
            'username' => 'newadminuser',
            'password' => 'password123',
            'roles' => ['ROLE_USER']
        ];

        $this->call('POST', '/users', [], $userData);
        $this->assertResponseStatusCodeSame(201);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('newadminuser', $data['username']);
        $this->assertArrayHasKey('id', $data);
    }
}
