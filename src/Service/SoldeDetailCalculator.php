<?php

namespace App\Service;

use App\Dto\Mcp\SoldeDetail;
use App\Dto\Mcp\SoldeDetailInput;
use App\Dto\Mcp\SoldeLigne;
use App\Dto\Mcp\SoldeTotal;
use App\Entity\Depense;
use App\Entity\Detail;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Explique un solde : ce qui a bougé sur une fenêtre récente, et pourquoi.
 *
 * Le solde lui-même est celui de `User::getSolde()`, à ceci près qu'on le
 * calcule ici sans le conjoint : le détail porte sur les mouvements de la
 * personne, et un total agrégé au couple ne bouclerait pas avec les lignes
 * listées. `user_me` continue d'exposer les deux.
 */
class SoldeDetailCalculator
{
    private const LIGNES_MAX = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function calculer(User $user, ?int $fenetreJours): SoldeDetail
    {
        $jours = $fenetreJours ?? SoldeDetailInput::DEFAUT_JOURS;

        if ($jours < 1 || $jours > SoldeDetailInput::MAX_JOURS) {
            throw new \InvalidArgumentException(\sprintf(
                'La fenêtre doit tenir entre 1 et %d jours (reçu : %d). Cet outil résume une période récente ; pour remonter plus loin, utilise `depenses_list`.',
                SoldeDetailInput::MAX_JOURS,
                $jours
            ));
        }

        $fin = new \DateTimeImmutable();
        $debut = $fin->modify(\sprintf('-%d days', $jours));

        $paye = $this->depensesPayees($user, $debut, $fin);
        $du = $this->partsSupportees($user, $debut, $fin);

        $totalPaye = array_sum(array_map(static fn (Depense $d): float => $d->montant, $paye));
        $totalDu = array_sum(array_map(static fn (Detail $d): float => $d->montant, $du));

        $solde = $this->soldeAvant($user, null);
        $mouvement = round($totalPaye - $totalDu, 2);

        return new SoldeDetail(
            soldeIndividuel: $solde,
            soldeIndividuelDebutPeriode: round($solde - $mouvement, 2),
            mouvement: $mouvement,
            debut: $debut->format(\DateTimeInterface::ATOM),
            fin: $fin->format(\DateTimeInterface::ATOM),
            jours: $jours,
            paye: new SoldeTotal(round($totalPaye, 2), \count($paye)),
            du: new SoldeTotal(round($totalDu, 2), \count($du)),
            joursDepuisDernierPaiement: $this->joursDepuisDernierPaiement($user, $fin),
            aAvance: $this->lignesAvancees($user, $paye),
            aCharge: $this->lignesACharge($user, $du),
        );
    }

    /**
     * Solde à une date donnée, ou aujourd'hui si elle est nulle : tout ce qui a
     * été payé, moins tout ce qui a été mis à sa charge.
     */
    private function soldeAvant(User $user, ?\DateTimeInterface $date): float
    {
        $paye = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(d.montant), 0)')
            ->from(Depense::class, 'd')
            ->where('d.payePar = :user')
            ->setParameter('user', $user);

        $du = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(det.montant), 0)')
            ->from(Detail::class, 'det')
            ->where('det.user = :user')
            ->setParameter('user', $user);

        if (null !== $date) {
            $paye->andWhere('d.date < :date')->setParameter('date', $date);
            $du->join('det.depense', 'dep')->andWhere('dep.date < :date')->setParameter('date', $date);
        }

        return round((float) $paye->getQuery()->getSingleScalarResult() - (float) $du->getQuery()->getSingleScalarResult(), 2);
    }

    /**
     * @return list<Depense>
     */
    private function depensesPayees(User $user, \DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('d')
            ->from(Depense::class, 'd')
            ->where('d.payePar = :user')
            ->andWhere('d.date >= :debut')
            ->andWhere('d.date <= :fin')
            ->setParameter('user', $user)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Detail>
     */
    private function partsSupportees(User $user, \DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('det', 'dep')
            ->from(Detail::class, 'det')
            ->join('det.depense', 'dep')
            ->where('det.user = :user')
            ->andWhere('dep.date >= :debut')
            ->andWhere('dep.date <= :fin')
            ->setParameter('user', $user)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }

    private function joursDepuisDernierPaiement(User $user, \DateTimeInterface $fin): ?int
    {
        $derniere = $this->em->createQueryBuilder()
            ->select('MAX(d.date)')
            ->from(Depense::class, 'd')
            ->where('d.payePar = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        if (null === $derniere) {
            return null;
        }

        return (int) (new \DateTimeImmutable((string) $derniere))->diff($fin)->days;
    }

    /**
     * Ce qu'il a avancé : le montant total moins sa propre part. Une dépense
     * qu'il paie sans y prendre de part compte donc pour son montant entier.
     *
     * @param list<Depense> $payees
     * @return list<SoldeLigne>
     */
    private function lignesAvancees(User $user, array $payees): array
    {
        $lignes = [];
        foreach ($payees as $depense) {
            $maPart = $this->partDe($user, $depense);
            $lignes[] = new SoldeLigne(
                id: $depense->id ?? 0,
                titre: $depense->titre,
                date: $depense->date->format(\DateTimeInterface::ATOM),
                montant: $depense->montant,
                payePar: $depense->payePar->getUsername(),
                maPart: $maPart,
                effet: round($depense->montant - $maPart, 2),
            );
        }

        return $this->plusGrosEffets($lignes);
    }

    /**
     * Ce qui est à sa charge et qu'un autre a payé : sa part, en négatif. Les
     * dépenses qu'il a payées lui-même sont déjà dans `aAvance`, où son effet
     * est compté net.
     *
     * @param list<Detail> $parts
     * @return list<SoldeLigne>
     */
    private function lignesACharge(User $user, array $parts): array
    {
        $lignes = [];
        foreach ($parts as $part) {
            $depense = $part->depense;
            if (null === $depense || $depense->payePar === $user) {
                continue;
            }

            $lignes[] = new SoldeLigne(
                id: $depense->id ?? 0,
                titre: $depense->titre,
                date: $depense->date->format(\DateTimeInterface::ATOM),
                montant: $depense->montant,
                payePar: $depense->payePar->getUsername(),
                maPart: $part->montant,
                effet: round(-$part->montant, 2),
            );
        }

        return $this->plusGrosEffets($lignes);
    }

    private function partDe(User $user, Depense $depense): float
    {
        foreach ($depense->details as $detail) {
            if ($detail->user === $user) {
                return $detail->montant;
            }
        }

        return 0.0;
    }

    /**
     * @param list<SoldeLigne> $lignes
     * @return list<SoldeLigne>
     */
    private function plusGrosEffets(array $lignes): array
    {
        usort($lignes, static fn (SoldeLigne $a, SoldeLigne $b): int => abs($b->effet) <=> abs($a->effet));

        return \array_slice($lignes, 0, self::LIGNES_MAX);
    }
}
