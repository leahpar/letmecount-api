<?php

namespace App\Dto\Mcp;

/**
 * Arguments de l'outil MCP `depenses_list`.
 *
 * Un outil de liste doit décrire ses arguments, pas la forme de sa sortie :
 * sans `input`, le Loader construit l'inputSchema depuis l'opération de
 * collection et produit un `type: array`, que le SDK refuse.
 */
class DepenseListInput
{
    /** Filtre sur l'IRI d'un tag, par exemple "/tags/3". */
    public ?string $tag = null;

    /** Numéro de page, à partir de 1. */
    public ?int $page = null;
}
