<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Supprime, puis confirme.
 *
 * Le processeur de suppression d'API Platform ne rend rien, ce qui donnait un
 * outil MCP répondant `null` : indistinguable d'une erreur avalée, au point
 * qu'il fallait un second appel pour savoir si la suppression avait eu lieu. Sur
 * la seule opération irréversible de la surface, c'est le pire endroit où être
 * ambigu.
 *
 * Le résultat est un CallToolResult, que StructuredContentProcessor renvoie tel
 * quel : une confirmation rendue en tableau associatif ressortirait sérialisée
 * en collection JSON-LD, `member: [true, "Depense", 160]`.
 *
 * @implements ProcessorInterface<object, CallToolResult>
 */
class McpDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<object, mixed> $removeProcessor
     */
    public function __construct(
        private readonly ProcessorInterface $removeProcessor,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $this->removeProcessor->process($data, $operation, $uriVariables, $context);

        $message = \sprintf(
            '%s %s supprimée définitivement.',
            $operation->getShortName() ?? 'Ressource',
            $uriVariables['id'] ?? ''
        );

        return new CallToolResult([new TextContent($message)], false, [
            'deleted' => true,
            'resource' => $operation->getShortName(),
            'id' => $uriVariables['id'] ?? null,
        ]);
    }
}
