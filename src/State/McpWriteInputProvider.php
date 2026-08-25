<?php

namespace App\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Exception\McpNotFoundException;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Fabrique l'objet d'entrée des outils MCP d'écriture.
 *
 * Le provider par défaut d'api-platform/mcp mappe les arguments avec
 * `object_mapper`, qui suit le modèle CQRS de la documentation : des propriétés
 * scalaires sur une classe dédiée. Nos outils écrivent des entités avec des
 * relations exprimées en IRI et une collection de détails imbriquée, que ce
 * mapping ne sait pas construire — il rend « Expected argument of type
 * App\Entity\Detail, array given ».
 *
 * On repasse donc par le dénormaliseur d'API Platform, celui-là même qui sert
 * les requêtes HTTP : IRI résolues, groupes de sérialisation respectés, et pour
 * une modification l'objet existant est chargé puis complété.
 *
 * @implements ProviderInterface<object>
 */
class McpWriteInputProvider implements ProviderInterface
{
    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly ProviderInterface $itemProvider,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        /** @var class-string $resourceClass */
        $resourceClass = $operation->getClass();
        $data = $context['mcp_data'] ?? [];
        unset($data['id']);

        $existing = null;
        if ($uriVariables) {
            $existing = $this->itemProvider->provide($operation, $uriVariables, $context);

            // Modifier ou supprimer suppose que la cible existe : le dire, plutôt
            // que de laisser le pipeline rendre un « Not Found » sec — ou pire,
            // pour une modification, créer une seconde dépense en silence.
            if (null === $existing) {
                throw new McpNotFoundException($operation->getShortName() ?? 'Ressource', $uriVariables);
            }
        }

        if ($operation instanceof HttpOperation && 'DELETE' === $operation->getMethod()) {
            return $existing;
        }

        $denormalizationContext = ($operation->getDenormalizationContext() ?? []) + [
            'resource_class' => $resourceClass,
            'operation' => $operation,
            'operation_name' => $operation->getName(),
        ];

        if (null !== $existing) {
            $denormalizationContext[AbstractNormalizer::OBJECT_TO_POPULATE] = $existing;
            $denormalizationContext['deep_object_to_populate'] = true;
        }

        try {
            return $this->denormalizer->denormalize($data, $resourceClass, 'jsonld', $denormalizationContext);
        } catch (SerializerException $e) {
            // « Invalid IRI "2". » ne dit pas ce qu'il fallait écrire, et un
            // agent qui a vu `depenses_list` accepter "2" pour le filtre `tag`
            // a toutes les raisons de réessayer la même chose.
            if (str_contains($e->getMessage(), 'Invalid IRI')) {
                throw new \RuntimeException(\sprintf(
                    '%s Les relations s\'écrivent sous forme d\'IRI, par exemple "/tags/3" ou "/users/4" — pas un identifiant nu.',
                    rtrim($e->getMessage(), ' ')
                ), 0, $e);
            }

            throw $e;
        }
    }
}
