<?php

namespace App\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Depense;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

class CurrentUserDepenseExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security
    ) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []): void
    {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = []): void
    {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass !== Depense::class) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        // Filtrer pour ne retourner que les dépenses où l'utilisateur est impliqué
        $subQueryBuilder = $queryBuilder->getEntityManager()->createQueryBuilder();
        $subQueryBuilder
            ->select('d1')
            ->from(\App\Entity\Detail::class, 'd1')
            ->where(sprintf('d1.depense = %s', $rootAlias))
            ->andWhere('d1.user = :current_user');

        $queryBuilder
            ->andWhere($queryBuilder->expr()->exists($subQueryBuilder->getDQL()))
            ->setParameter('current_user', $user);
    }
}
