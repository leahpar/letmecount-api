<?php

namespace App\Dto\Mcp;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Argument des outils MCP qui portent sur une dépense précise.
 *
 * Sans `input`, le Loader construit l'inputSchema depuis la ressource entière :
 * `depense_get` et `depense_delete` annonçaient alors le corps complet d'une
 * dépense, avec six champs `required` que le serveur n'a jamais lus, et pas
 * l'identifiant qui est le seul argument qu'ils utilisent vraiment. Un agent qui
 * suivait ce schéma fabriquait une fausse dépense pour lire — ou pour supprimer.
 */
class DepenseIdInput
{
    /** Identifiant de la dépense, tel que rendu par `depenses_list`. */
    #[Assert\NotNull]
    public int $id;
}
