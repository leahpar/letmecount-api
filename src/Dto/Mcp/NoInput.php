<?php

namespace App\Dto\Mcp;

/**
 * Entrée vide, pour les outils MCP de liste qui n'acceptent aucun argument.
 *
 * Sans `input`, le Loader construit l'inputSchema depuis l'opération de
 * collection et produit un `type: array` que le SDK refuse. Une classe sans
 * propriété donne un objet vide, qui est la description exacte de ces outils.
 */
class NoInput
{
}
