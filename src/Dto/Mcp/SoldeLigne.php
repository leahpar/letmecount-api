<?php

namespace App\Dto\Mcp;

/**
 * Une dépense de la fenêtre, vue depuis le solde de l'utilisateur courant.
 */
class SoldeLigne
{
    public function __construct(
        /** Identifiant de la dépense, utilisable avec `depense_get`. */
        public int $id,
        public string $titre,
        /** Date de la dépense, au format ISO 8601. */
        public string $date,
        /** Montant total de la dépense, toutes personnes confondues. */
        public float $montant,
        /** Nom de la personne qui a payé — pas son IRI : cet outil sert à raconter. */
        public string $payePar,
        /** Part de l'utilisateur courant dans cette dépense. Vaut 0 s'il n'y participe pas. */
        public float $maPart,
        /**
         * Effet de cette dépense sur le solde, déjà signé et calculé : ce qui a
         * été avancé moins sa propre part quand il a payé, sa part en négatif
         * sinon. La somme des `effet` de la période vaut `mouvement`.
         */
        public float $effet,
    ) {}
}
