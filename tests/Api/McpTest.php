<?php

namespace App\Tests\Api;

/**
 * Parcours MCP : initialize, tools/list, et des tools/call en lecture comme en
 * écriture. L'endpoint tombe sous le firewall `^/`, donc l'authentification est
 * celle des autres tests — un Bearer JWT posé par AuthenticatedApiTestCase.
 */
class McpTest extends AuthenticatedApiTestCase
{
    private int $rpcId = 0;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function rpc(string $method, array $params = [], bool $expectResult = true): array
    {
        $this->client->request(
            'POST',
            '/mcp',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json, text/event-stream',
            ],
            json_encode([
                'jsonrpc' => '2.0',
                'id' => ++$this->rpcId,
                'method' => $method,
                'params' => $params ?: new \stdClass(),
            ])
        );

        $this->assertResponseIsSuccessful();
        $payload = $this->decode($this->client->getResponse()->getContent());

        if ($expectResult) {
            $this->assertArrayNotHasKey('error', $payload, json_encode($payload['error'] ?? null));
            $this->assertArrayHasKey('result', $payload);
        }

        return $payload;
    }

    /**
     * Le transport streamable répond soit en JSON, soit en SSE selon ce que le
     * serveur choisit : on accepte les deux plutôt que de figer le choix.
     *
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if (str_starts_with(ltrim($body), '{')) {
            return json_decode($body, true);
        }

        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data:')) {
                return json_decode(trim(substr($line, 5)), true);
            }
        }

        $this->fail('Réponse MCP illisible : '.substr($body, 0, 200));
    }

    protected function initialize(): void
    {
        $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
        ]);

        // Le serveur pose un Mcp-Session-Id que les requêtes suivantes doivent renvoyer.
        if ($sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id')) {
            $this->client->setServerParameter('HTTP_Mcp-Session-Id', $sessionId);
        }

        $this->client->request(
            'POST',
            '/mcp',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'],
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
        );
    }

    public function testEndpointRequiresAuthentication(): void
    {
        // Le client de la classe parente porte déjà un Bearer : on le retire
        // plutôt que d'en créer un second, le noyau n'étant démarrable qu'une fois.
        $this->client->setServerParameter('HTTP_Authorization', '');
        $this->client->request(
            'POST',
            '/mcp',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => new \stdClass()])
        );

        $this->assertResponseStatusCodeSame(401);
        $this->assertResponseHasHeader('WWW-Authenticate');
    }

    public function testToolsListExposesTheExpectedSurface(): void
    {
        $this->initialize();
        $result = $this->rpc('tools/list')['result'];

        $names = array_column($result['tools'], 'name');
        sort($names);

        $this->assertSame([
            'depense_create',
            'depense_delete',
            'depense_get',
            'depense_update',
            'depenses_list',
            'logs_list',
            'tag_create',
            'tags_list',
            'user_me',
            'users_list',
        ], $names);

        // Rien d'administratif n'est exposé : ni création d'utilisateur, ni
        // jeton d'invitation, ni passkey.
        foreach ($names as $name) {
            $this->assertStringNotContainsString('token', $name);
            $this->assertStringNotContainsString('webauthn', $name);
        }
        $this->assertNotContains('user_create', $names);

        // Le SDK exige un inputSchema de type object sur chaque outil.
        foreach ($result['tools'] as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type'] ?? null, $tool['name']);
        }
    }

    public function testDepensesListReadsFromTheDatabase(): void
    {
        $depense = $this->createDepense($this->user, 42.0, 'Vue par MCP');
        $this->createDetail($this->user, 42.0, $depense);

        $this->initialize();
        $result = $this->rpc('tools/call', ['name' => 'depenses_list', 'arguments' => new \stdClass()])['result'];

        $titres = array_column($this->items($result), 'titre');
        $this->assertContains('Vue par MCP', $titres);
    }

    /**
     * La liste passe par le provider Doctrine, donc par CurrentUserDepenseExtension :
     * une dépense d'un tiers ne doit pas remonter.
     */
    public function testDepensesListHidesOtherUsersExpenses(): void
    {
        $autre = $this->createUser('autre');
        $sienne = $this->createDepense($autre, 99.0, 'Dépense d\'un tiers');
        $this->createDetail($autre, 99.0, $sienne);

        $this->initialize();
        $result = $this->rpc('tools/call', ['name' => 'depenses_list', 'arguments' => new \stdClass()])['result'];

        $titres = array_column($this->items($result), 'titre');
        $this->assertNotContains('Dépense d\'un tiers', $titres);
    }

    public function testDepensesListArgumentsAreHonoured(): void
    {
        $cible = $this->createTag('Cible');
        $autre = $this->createTag('Autre');

        $dansCible = $this->createDepense($this->user, 10.0, 'Dans la cible', $cible);
        $this->createDetail($this->user, 10.0, $dansCible);
        $horsCible = $this->createDepense($this->user, 20.0, 'Hors cible', $autre);
        $this->createDetail($this->user, 20.0, $horsCible);

        $this->initialize();
        $result = $this->rpc('tools/call', [
            'name' => 'depenses_list',
            'arguments' => ['tag' => '/tags/'.$cible->id],
        ])['result'];

        $titres = array_column($this->items($result), 'titre');
        $this->assertContains('Dans la cible', $titres);
        $this->assertNotContains('Hors cible', $titres);
    }

    public function testDepenseCreateWritesAndValidates(): void
    {
        $tag = $this->createTag();
        $this->initialize();

        $arguments = [
            'date' => '2026-02-01T00:00:00+00:00',
            'montant' => 30.0,
            'titre' => 'Créée par MCP',
            'partage' => 'montants',
            'tag' => '/tags/'.$tag->id,
            'payePar' => '/users/'.$this->user->id,
            'details' => [
                ['user' => '/users/'.$this->user->id, 'parts' => 1, 'montant' => 30.0],
            ],
        ];

        $this->rpc('tools/call', ['name' => 'depense_create', 'arguments' => $arguments]);

        $this->em->clear();
        $creee = $this->em->getRepository(\App\Entity\Depense::class)->findOneBy(['titre' => 'Créée par MCP']);
        $this->assertNotNull($creee);
        $this->assertSame(30.0, $creee->montant);
    }

    /**
     * Le Handler désactive la validation quand l'opération ne tranche pas : les
     * outils d'écriture portent `validate: true`, sans quoi un agent pourrait
     * écrire une dépense que DepenseConstraint refuse au front.
     */
    public function testDepenseCreateRejectsIncoherentAmounts(): void
    {
        $tag = $this->createTag();
        $this->initialize();

        $arguments = [
            'date' => '2026-02-01T00:00:00+00:00',
            'montant' => 100.0,
            'titre' => 'Incohérente',
            'partage' => 'montants',
            'tag' => '/tags/'.$tag->id,
            'payePar' => '/users/'.$this->user->id,
            'details' => [
                ['user' => '/users/'.$this->user->id, 'parts' => 1, 'montant' => 10.0],
            ],
        ];

        $payload = $this->rpc('tools/call', ['name' => 'depense_create', 'arguments' => $arguments], false);

        $this->em->clear();
        $this->assertNull(
            $this->em->getRepository(\App\Entity\Depense::class)->findOneBy(['titre' => 'Incohérente']),
            'Une dépense incohérente a été persistée : '.json_encode($payload)
        );
    }

    public function testDepenseGetReadsOneExpense(): void
    {
        // Deux dépenses, sinon le test passerait même si l'identifiant était ignoré.
        $autre = $this->createDepense($this->user, 99.0, 'Pas celle-là');
        $this->createDetail($this->user, 99.0, $autre);
        $depense = $this->createDepense($this->user, 12.0, 'Lue par identifiant');
        $this->createDetail($this->user, 12.0, $depense);

        $this->initialize();
        $result = $this->rpc('tools/call', [
            'name' => 'depense_get',
            'arguments' => ['id' => $depense->id],
        ])['result'];

        $this->assertSame('Lue par identifiant', $this->content($result)['titre'] ?? null);
    }

    public function testDepenseUpdateModifiesInPlace(): void
    {
        $depense = $this->createDepense($this->user, 15.0, 'Avant');
        $this->createDetail($this->user, 15.0, $depense);
        $id = $depense->id;

        $this->initialize();
        $this->rpc('tools/call', [
            'name' => 'depense_update',
            'arguments' => ['id' => $id, 'titre' => 'Après'],
        ]);

        $this->em->clear();
        $this->assertSame('Après', $this->em->getRepository(\App\Entity\Depense::class)->find($id)->titre);
    }

    public function testDepenseDeleteRemovesTheExpense(): void
    {
        $depense = $this->createDepense($this->user, 8.0, 'À supprimer');
        $this->createDetail($this->user, 8.0, $depense);
        $id = $depense->id;

        $this->initialize();
        $this->rpc('tools/call', ['name' => 'depense_delete', 'arguments' => ['id' => $id]], false);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(\App\Entity\Depense::class)->find($id));
    }

    public function testTagsListAndCreate(): void
    {
        $this->initialize();

        $this->rpc('tools/call', [
            'name' => 'tag_create',
            'arguments' => ['libelle' => 'Courses MCP'],
        ]);

        $result = $this->rpc('tools/call', ['name' => 'tags_list', 'arguments' => new \stdClass()])['result'];
        $this->assertContains('Courses MCP', array_column($this->items($result), 'libelle'));
    }

    public function testUsersListIsFilterable(): void
    {
        $this->createUser('cherchable');
        $this->initialize();

        $result = $this->rpc('tools/call', [
            'name' => 'users_list',
            'arguments' => ['username' => 'cherchab'],
        ])['result'];

        $usernames = array_column($this->items($result), 'username');
        $this->assertSame(['cherchable'], $usernames);
    }

    public function testUserMeReturnsTheCurrentUser(): void
    {
        $this->initialize();
        $result = $this->rpc('tools/call', ['name' => 'user_me', 'arguments' => new \stdClass()])['result'];

        $this->assertSame('testuser', $this->content($result)['username'] ?? null);
    }

    public function testLogsListReadsTheActivityFeed(): void
    {
        $tag = $this->createTag();
        $this->initialize();

        // Une création par MCP alimente le journal comme n'importe quelle écriture.
        $this->rpc('tools/call', ['name' => 'depense_create', 'arguments' => [
            'date' => '2026-03-01T00:00:00+00:00',
            'montant' => 5.0,
            'titre' => 'Tracée',
            'partage' => 'montants',
            'tag' => '/tags/'.$tag->id,
            'payePar' => '/users/'.$this->user->id,
            'details' => [['user' => '/users/'.$this->user->id, 'parts' => 1, 'montant' => 5.0]],
        ]]);

        $result = $this->rpc('tools/call', ['name' => 'logs_list', 'arguments' => new \stdClass()])['result'];
        $this->assertContains('Tracée', array_column($this->items($result), 'libelle'));
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function content(array $result): array
    {
        $content = $result['structuredContent'] ?? null;

        if (null === $content && isset($result['content'][0]['text'])) {
            $content = json_decode($result['content'][0]['text'], true);
        }

        return is_array($content) ? $content : [];
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private function items(array $result): array
    {
        $content = $result['structuredContent'] ?? null;

        if (null === $content && isset($result['content'][0]['text'])) {
            $content = json_decode($result['content'][0]['text'], true);
        }

        if (isset($content['member'])) {
            return $content['member'];
        }

        if (isset($content['hydra:member'])) {
            return $content['hydra:member'];
        }

        return is_array($content) ? $content : [];
    }
}
