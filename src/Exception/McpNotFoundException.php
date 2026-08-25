<?php

namespace App\Exception;

/**
 * Le message de cette exception est renvoyé tel quel à l'agent : le SDK MCP
 * transforme toute Throwable en erreur JSON-RPC portant son `getMessage()`.
 * C'est donc lui, et lui seul, qui dit à l'agent quoi corriger.
 */
class McpNotFoundException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $uriVariables
     */
    public function __construct(string $shortName, array $uriVariables)
    {
        parent::__construct(\sprintf(
            '%s %s introuvable. Vérifie cet identifiant avec l\'outil de liste correspondant ; il a pu être supprimé.',
            $shortName,
            implode(', ', array_map(
                static fn (string $k, mixed $v): string => \sprintf('%s=%s', $k, \is_scalar($v) ? $v : '?'),
                array_keys($uriVariables),
                $uriVariables
            )) ?: '(aucun identifiant fourni)'
        ));
    }
}
