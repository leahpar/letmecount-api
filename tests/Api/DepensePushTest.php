<?php

namespace App\Tests\Api;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\PushSender;

class DepensePushTest extends AuthenticatedApiTestCase
{
    /** @var list<array{string, array<string, string>}> */
    private array $envois = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envois = [];

        $sender = $this->createMock(PushSender::class);
        $sender->method('send')->willReturnCallback(
            function (iterable $users, array $payload): int {
                foreach ($users as $user) {
                    $this->envois[] = [$user->getUsername(), $payload];
                }

                return count($this->envois);
            }
        );

        static::getContainer()->set(PushSender::class, $sender);
    }

    /**
     * @param list<array{User, float}> $parts
     */
    private function postDepense(Tag $tag, User $payePar, float $montant, array $parts, string $titre = 'Courses'): void
    {
        $this->call('POST', '/depenses', [], [
            'date' => '2026-08-24T00:00:00+00:00',
            'montant' => $montant,
            'titre' => $titre,
            'partage' => 'montants',
            'tag' => '/tags/'.$tag->id,
            'payePar' => '/users/'.$payePar->id,
            'details' => array_map(
                fn (array $part) => [
                    'user' => '/users/'.$part[0]->id,
                    'parts' => 1,
                    'montant' => $part[1],
                ],
                $parts
            ),
        ]);
    }

    /**
     * Le cœur du ciblage : seuls les gens que la dépense touche sont notifiés,
     * l'auteur est exclu, et un participant du tag absent des détails aussi —
     * lui, c'est l'activité qui le tient au courant.
     */
    public function testNouvelleDepenseNotifieLesConcernesSaufLauteur(): void
    {
        $bob = $this->createUser('bob');
        $carole = $this->createUser('carole');
        $tag = $this->createTag('Vacances', [$this->user, $bob, $carole]);

        $this->postDepense($tag, $this->user, 50.0, [[$this->user, 25.0], [$bob, 25.0]]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertCount(1, $this->envois);
        $this->assertSame('bob', $this->envois[0][0]);
    }

    public function testLaNotificationPorteLaPartDuDestinataire(): void
    {
        $bob = $this->createUser('bob');
        $tag = $this->createTag('Vacances', [$this->user, $bob]);

        $this->postDepense($tag, $this->user, 50.0, [[$this->user, 30.0], [$bob, 20.0]]);

        [, $payload] = $this->envois[0];
        $this->assertSame('Courses', $payload['title']);
        $this->assertSame('testuser a payé 50,00 € · 20,00 € pour toi', $payload['body']);
        $this->assertStringStartsWith('/expenses/', $payload['url']);
    }

    /**
     * Un remboursement n'a qu'un détail — le bénéficiaire — et se formule
     * autrement : « 20 € pour toi » serait faux.
     */
    public function testTransfertNotifieLeBeneficiaire(): void
    {
        $bob = $this->createUser('bob');
        $tag = $this->createTag('Transfert', [$this->user, $bob]);

        $this->postDepense($tag, $this->user, 20.0, [[$bob, 20.0]], 'Remboursement');

        $this->assertCount(1, $this->envois);
        $this->assertSame('bob', $this->envois[0][0]);
        $this->assertSame("testuser t'a remboursé 20,00 €", $this->envois[0][1]['body']);
    }

    public function testUneDepenseQuiNeConcerneQueSonAuteurNeNotifiePersonne(): void
    {
        $tag = $this->createTag('Perso', [$this->user]);

        $this->postDepense($tag, $this->user, 12.0, [[$this->user, 12.0]]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame([], $this->envois);
    }
}
