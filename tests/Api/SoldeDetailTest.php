<?php

namespace App\Tests\Api;

use App\Entity\Depense;
use App\Entity\Detail;
use App\Entity\User;

/**
 * L'outil `solde_detail` explique un solde plutôt que de le donner : c'est la
 * question qui vient juste après `user_me`, et à laquelle il fallait sinon
 * rapatrier toutes les dépenses pour répondre.
 */
class SoldeDetailTest extends McpTestCase
{
    /**
     * @param list<array{User, float}> $parts
     */
    private function depenseDatee(User $payePar, float $montant, string $titre, string $ilYA, array $parts): Depense
    {
        $depense = new Depense();
        $depense->titre = $titre;
        $depense->montant = $montant;
        $depense->date = new \DateTime($ilYA);
        $depense->partage = 'montants';
        $depense->payePar = $payePar;
        $depense->tag = $this->createTag();
        $this->em->persist($depense);

        foreach ($parts as [$user, $part]) {
            $detail = new Detail();
            $detail->user = $user;
            $detail->montant = $part;
            $detail->parts = 0;
            $detail->depense = $depense;
            $this->em->persist($detail);
        }

        $this->em->flush();

        return $depense;
    }

    /**
     * @return array<string, mixed>
     */
    private function solde(array $arguments = []): array
    {
        $this->initialize();

        return $this->content($this->rpc('tools/call', [
            'name' => 'solde_detail',
            'arguments' => $arguments ?: new \stdClass(),
        ])['result']);
    }

    public function testExplainsWhatMovedTheBalance(): void
    {
        $autre = $this->createUser('colocataire');

        // Avancé : 300 payés, 100 pour lui, donc +200 sur son solde.
        $this->depenseDatee($this->user, 300.0, 'Logement', '-5 days', [
            [$this->user, 100.0], [$autre, 200.0],
        ]);
        // À charge : payé par l'autre, 80 pour lui, donc -80.
        $this->depenseDatee($autre, 160.0, 'Billets d\'avion', '-3 days', [
            [$this->user, 80.0], [$autre, 80.0],
        ]);

        $detail = $this->solde();

        $this->assertEqualsWithDelta(120.0, $detail['mouvement'], 0.001, 'La somme des effets vaut le mouvement');
        $this->assertEqualsWithDelta(300.0, $detail['paye']['total'], 0.001);
        $this->assertSame(1, $detail['paye']['nombre']);
        $this->assertEqualsWithDelta(180.0, $detail['du']['total'], 0.001);
        $this->assertSame(2, $detail['du']['nombre']);

        // Le solde de départ est bien le solde actuel moins ce qui a bougé.
        $this->assertEqualsWithDelta(
            $detail['solde'] - $detail['mouvement'],
            $detail['soldeDebutPeriode'],
            0.001
        );

        $this->assertSame('Logement', $detail['aAvance'][0]['titre']);
        $this->assertEqualsWithDelta(200.0, $detail['aAvance'][0]['effet'], 0.001);
        $this->assertEqualsWithDelta(100.0, $detail['aAvance'][0]['maPart'], 0.001);

        $this->assertSame('Billets d\'avion', $detail['aCharge'][0]['titre']);
        $this->assertEqualsWithDelta(-80.0, $detail['aCharge'][0]['effet'], 0.001);
    }

    /**
     * Le payeur est nommé, pas donné en IRI : sans ça, raconter « Mathieu a pris
     * les billets » coûte un users_list de plus.
     */
    public function testNamesThePayerInPlainText(): void
    {
        $mathieu = $this->createUser('Mathieu');
        $this->depenseDatee($mathieu, 100.0, 'Restaurant', '-2 days', [
            [$this->user, 50.0], [$mathieu, 50.0],
        ]);

        $detail = $this->solde();

        $this->assertSame('Mathieu', $detail['aCharge'][0]['payePar']);
    }

    /**
     * Une dépense avancée sans y prendre de part bouge le solde de son montant
     * entier : c'est le cas qui pèse le plus lourd, et le plus facile à rater.
     */
    public function testAdvancedWithoutTakingAShareCountsInFull(): void
    {
        $autre = $this->createUser('beneficiaire');
        $this->depenseDatee($this->user, 250.0, 'Cadeau commun', '-1 days', [
            [$autre, 250.0],
        ]);

        $detail = $this->solde();

        $this->assertEqualsWithDelta(0.0, $detail['aAvance'][0]['maPart'], 0.001);
        $this->assertEqualsWithDelta(250.0, $detail['aAvance'][0]['effet'], 0.001);
        $this->assertEqualsWithDelta(250.0, $detail['mouvement'], 0.001);
    }

    /**
     * L'absence d'activité est une explication en soi : un mouvement nul avec un
     * dernier paiement lointain décrit quelqu'un qui était déjà dans le rouge.
     */
    public function testSilenceIsAnExplanation(): void
    {
        $autre = $this->createUser('payeur');
        // Hors fenêtre, et payée par quelqu'un d'autre.
        $this->depenseDatee($autre, 100.0, 'Vieille dépense', '-200 days', [
            [$this->user, 100.0], [$autre, 0.0],
        ]);
        // Son dernier paiement à lui, encore plus ancien.
        $this->depenseDatee($this->user, 10.0, 'Vieux paiement', '-300 days', [
            [$this->user, 10.0],
        ]);

        $detail = $this->solde();

        $this->assertEqualsWithDelta(0.0, $detail['mouvement'], 0.001);
        $this->assertSame([], $detail['aAvance']);
        $this->assertSame([], $detail['aCharge']);
        $this->assertGreaterThanOrEqual(299, $detail['joursDepuisDernierPaiement']);
        $this->assertEqualsWithDelta($detail['solde'], $detail['soldeDebutPeriode'], 0.001);
    }

    public function testWindowIsBoundedAndSaysWhereToGoInstead(): void
    {
        $this->initialize();
        $payload = $this->rpc('tools/call', [
            'name' => 'solde_detail',
            'arguments' => ['fenetreJours' => 90],
        ], false);

        $message = $payload['error']['message'] ?? '';
        $this->assertStringContainsString('60', $message);
        $this->assertStringContainsString('depenses_list', $message);
    }

    /**
     * Cinq lignes au maximum, les plus gros effets d'abord : c'est un outil de
     * synthèse, pas un export paginé.
     */
    public function testListsAreCappedAndSortedByImpact(): void
    {
        $autre = $this->createUser('tiers');
        foreach ([12.0, 40.0, 5.0, 80.0, 25.0, 60.0, 3.0] as $i => $montant) {
            $this->depenseDatee($autre, $montant, "Dépense $i", '-'.($i + 1).' days', [
                [$this->user, $montant], [$autre, 0.0],
            ]);
        }

        $detail = $this->solde();

        $this->assertCount(5, $detail['aCharge']);
        $effets = array_map(static fn (array $l): float => abs($l['effet']), $detail['aCharge']);
        $this->assertEqualsWithDelta([80.0, 60.0, 40.0, 25.0, 12.0], $effets, 0.001);
    }
}
