<?php

namespace App\Tests\Api;

/**
 * Parcours MCP : initialize, tools/list, et des tools/call en lecture comme en
 * écriture. L'endpoint tombe sous le firewall `^/`, donc l'authentification est
 * celle des autres tests — un Bearer JWT posé par AuthenticatedApiTestCase.
 */
class McpTest extends McpTestCase
{

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
        // Le pointeur RFC 9728 par lequel le client découvre où s'authentifier :
        // c'est sur cette réponse-là qu'il compte, elle est vérifiée en propre
        // dans OAuthMetadataTest.
        $this->assertStringContainsString(
            'resource_metadata=',
            $this->client->getResponse()->headers->get('WWW-Authenticate') ?? '',
        );
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
            'solde_detail',
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

    /**
     * Un agent n'a que le schéma pour savoir quoi envoyer : s'il décrit autre
     * chose que ce que l'outil lit vraiment, l'outil répond quand même et
     * l'agent apprend faux. Les outils d'élément ont d'abord annoncé le corps
     * entier d'une dépense, six champs `required` qu'ils n'ouvraient jamais, et
     * pas l'identifiant qui est leur seul argument utile.
     */
    public function testToolInputSchemasDescribeWhatIsActuallyRead(): void
    {
        $this->initialize();
        $schemas = [];
        foreach ($this->rpc('tools/list')['result']['tools'] as $tool) {
            $schemas[$tool['name']] = $tool['inputSchema'];
        }

        foreach (['depense_get', 'depense_delete'] as $name) {
            $this->assertSame(['id'], array_keys($schemas[$name]['properties'] ?? []), $name);
            $this->assertSame(['id'], $schemas[$name]['required'] ?? [], $name);
        }

        $this->assertSame(['id'], $schemas['depense_update']['required'] ?? []);
        $this->assertContains('titre', array_keys($schemas['depense_update']['properties'] ?? []));

        // user_me ne prend aucun argument. Son schéma reprenait la ressource
        // entière, jusqu'au jeton de liaison à usage unique.
        $this->assertSame([], array_keys($schemas['user_me']['properties'] ?? []));

        foreach ($schemas as $name => $schema) {
            $this->assertArrayNotHasKey('token', $schema['properties'] ?? [], $name);
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

    /**
     * La suppression est la seule opération irréversible de la surface : elle
     * doit confirmer. Elle répondait `null`, qu'un agent ne peut pas distinguer
     * d'une erreur avalée.
     */
    public function testDepenseDeleteConfirmsWhatItDid(): void
    {
        $depense = $this->createDepense($this->user, 8.0, 'À confirmer');
        $this->createDetail($this->user, 8.0, $depense);
        $id = $depense->id;

        $this->initialize();
        $result = $this->rpc('tools/call', ['name' => 'depense_delete', 'arguments' => ['id' => $id]])['result'];

        $content = $this->content($result);
        $this->assertTrue($content['deleted'] ?? false);
        $this->assertSame($id, $content['id'] ?? null);
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
     * Le message d'erreur est tout ce dont dispose un agent pour se corriger.
     * « Not Found » ne distinguait ni l'identifiant inexistant, ni la ressource
     * supprimée, ni le nom d'argument erroné — trois causes, une seule sortie.
     */
    public function testNotFoundErrorsSayWhatIsMissing(): void
    {
        $this->initialize();

        foreach (['depense_get', 'depense_delete'] as $outil) {
            $payload = $this->rpc('tools/call', [
                'name' => $outil,
                'arguments' => ['id' => 99999999],
            ], false);

            $message = $payload['error']['message'] ?? ($payload['result']['content'][0]['text'] ?? '');
            $this->assertStringContainsString('Depense', $message, $outil);
            $this->assertStringContainsString('99999999', $message, $outil);
            $this->assertStringContainsString('introuvable', $message, $outil);
        }
    }

}
