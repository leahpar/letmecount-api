<?php

namespace App\Dto\Mcp;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\McpTool;
use App\State\SoldeDetailProvider;

/**
 * Ce qui explique le solde de l'utilisateur courant sur une fenêtre récente.
 *
 * Ce n'est pas une ressource : rien n'est stocké, rien n'est adressable. C'est
 * un calcul, exposé uniquement en MCP — le front fait la même chose côté client
 * à partir des dépenses qu'il a déjà chargées.
 */
// Pas d'opération HTTP : cette classe n'existe que pour MCP. Sans ce
// `operations: []`, la présence d'un McpTool en fait une ressource API Platform
// complète, et API Platform lui génère un /solde_details avec ses verbes — un
// endpoint que personne n'a demandé, que rien ne teste, et qui se retrouve
// publié dans openapi.json.
#[ApiResource(operations: [])]
#[McpTool(
    name: 'solde_detail',
    description: 'Explique le solde de l\'utilisateur courant sur une fenêtre récente : ce qui a bougé, et pourquoi. Il porte sur `soldeIndividuel`, celui de la personne seule — `user_me.solde` y ajoute le conjoint quand il y en a un, et peut donc être de signe opposé. Le solde vaut ce qu\'elle a payé moins ce qu\'elle doit, toutes dépenses confondues et tous tags confondus : négatif, il doit au groupe ; positif, le groupe lui doit. Le groupe, c\'est l\'ensemble des utilisateurs, et il n\'y en a qu\'un. Comparer `soldeIndividuelDebutPeriode` à `soldeIndividuel` dit si la situation vient de bouger ou si elle était déjà là : un `mouvement` proche de zéro avec un `joursDepuisDernierPaiement` élevé décrit quelqu\'un qui n\'a rien payé depuis longtemps, ce qui est une explication en soi. Les montants sont en euros. Pour lister des dépenses au-delà de cette fenêtre, utiliser `depenses_list`.',
    input: SoldeDetailInput::class,
    uriVariables: [],
    provider: SoldeDetailProvider::class,
    security: "is_granted('IS_AUTHENTICATED_FULLY')"
)]
class SoldeDetail
{
    public function __construct(
        /**
         * Solde de l'utilisateur seul, sans son conjoint : négatif, il doit au
         * groupe ; positif, le groupe lui doit. C'est le `soldeIndividuel` de
         * `user_me`, et non son `solde`, qui agrège le couple — pour quelqu'un
         * qui a un conjoint, les deux peuvent être de signes opposés. C'est
         * celui-ci que les lignes ci-dessous expliquent.
         */
        public float $soldeIndividuel,
        /** Ce que valait `soldeIndividuel` au début de la fenêtre. */
        public float $soldeIndividuelDebutPeriode,
        /** Variation sur la fenêtre, soit `soldeIndividuel` moins `soldeIndividuelDebutPeriode`. */
        public float $mouvement,
        /** Début de la fenêtre observée, au format ISO 8601. */
        public string $debut,
        /** Fin de la fenêtre, c'est-à-dire maintenant. */
        public string $fin,
        /** Profondeur de la fenêtre, en jours. */
        public int $jours,
        /** Ce que l'utilisateur a payé pendant la fenêtre, montant total et nombre de dépenses. */
        #[ApiProperty(genId: false)]
        public SoldeTotal $paye,
        /** Ce qui a été mis à sa charge pendant la fenêtre, montant total et nombre de parts. */
        #[ApiProperty(genId: false)]
        public SoldeTotal $du,
        /**
         * Nombre de jours depuis la dernière dépense qu'il a payée, sans limite
         * de fenêtre. `null` s'il n'a jamais rien payé.
         */
        public ?int $joursDepuisDernierPaiement,
        /**
         * Ce qu'il a avancé pour les autres pendant la fenêtre, du plus gros
         * effet au plus petit, cinq au maximum.
         *
         * @var list<SoldeLigne>
         */
        #[ApiProperty(genId: false)]
        public array $aAvance,
        /**
         * Ce qui a été mis à sa charge pendant la fenêtre et payé par quelqu'un
         * d'autre, du plus gros effet au plus petit, cinq au maximum.
         *
         * @var list<SoldeLigne>
         */
        #[ApiProperty(genId: false)]
        public array $aCharge,
    ) {}
}
