<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;

/**
 * Fait passer les arguments d'un outil MCP de liste pour des filtres.
 *
 * Les filtres et la pagination d'API Platform se lisent dans
 * `$context['filters']`, que le pipeline HTTP remplit depuis la query string.
 * Une requête MCP n'en a pas : sans ce relais, les arguments déclarés dans
 * l'inputSchema sont acceptés puis ignorés en silence — l'outil annoncerait un
 * filtre qui ne filtre rien.
 *
 * @implements ProviderInterface<object>
 */
class McpCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly ProviderInterface $collectionProvider,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $arguments = array_filter(
            $context['mcp_data'] ?? [],
            static fn ($value) => null !== $value && '' !== $value
        );

        $context['filters'] = $arguments + ($context['filters'] ?? []);

        return $this->collectionProvider->provide($operation, $uriVariables, $context);
    }
}
