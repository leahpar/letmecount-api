<?php

namespace App\Dto\Mcp;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments de l'outil MCP `depense_update`.
 *
 * La modification est **partielle** : seuls les champs fournis sont écrasés, les
 * autres gardent leur valeur. C'est ce que ce DTO dit et que la ressource
 * complète ne disait pas — un agent qui renvoyait le corps entier lu juste avant
 * réécrivait la répartition sans le vouloir.
 */
class DepenseUpdateInput
{
    /** Identifiant de la dépense à modifier. */
    #[Assert\NotNull]
    public int $id;

    /** Nouveau titre. */
    public ?string $titre = null;

    /** Nouveau montant total. Doit rester égal à la somme des détails. */
    public ?float $montant = null;

    /** Nouvelle date, au format ISO 8601. */
    public ?string $date = null;

    /** "parts" ou "montants" — voir la description de `depense_create`. */
    public ?string $partage = null;

    /** IRI du tag, par exemple "/tags/3". */
    public ?string $tag = null;

    /** IRI de la personne qui a payé, par exemple "/users/4". */
    public ?string $payePar = null;

    /**
     * Répartition complète, si elle change. Fournir la liste entière : elle
     * remplace l'ancienne. Chaque entrée vaut
     * {"user": "/users/4", "parts": 1, "montant": 12.5}.
     *
     * @var list<array{user: string, parts: int, montant: float}>|null
     */
    public ?array $details = null;
}
