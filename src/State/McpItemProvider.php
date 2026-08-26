<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Exception\McpNotFoundException;

/**
 * Lit une ressource par son identifiant, et dit clairement quand elle n'existe
 * pas.
 *
 * Sans ça, le pipeline rend un « Not Found » sec, identique pour un identifiant
 * inexistant, une ressource supprimée et — surtout — un nom d'argument erroné.
 * Un agent ne peut pas distinguer les trois, donc il retente au hasard.
 *
 * @implements ProviderInterface<object>
 */
class McpItemProvider implements ProviderInterface
{
    public function __construct(
        private readonly ProviderInterface $itemProvider,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        $item = $this->itemProvider->provide($operation, $uriVariables, $context);

        if (null === $item) {
            throw new McpNotFoundException($operation->getShortName() ?? 'Ressource', $uriVariables);
        }

        return $item;
    }
}
