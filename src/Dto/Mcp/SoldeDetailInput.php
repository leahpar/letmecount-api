<?php

namespace App\Dto\Mcp;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments de l'outil MCP `solde_detail`.
 */
class SoldeDetailInput
{
    public const DEFAUT_JOURS = 28;
    public const MAX_JOURS = 60;

    /**
     * Profondeur de la fenêtre observée, en jours, comptée depuis aujourd'hui.
     * 28 par défaut, 60 au maximum : c'est un outil de synthèse, pas un export.
     */
    #[Assert\Range(min: 1, max: self::MAX_JOURS)]
    public ?int $fenetreJours = null;
}
