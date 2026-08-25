<?php

namespace App\Repository;

use App\Entity\OAuthClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthClient>
 */
class OAuthClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthClient::class);
    }

    public function findOneByClientId(string $clientId): ?OAuthClient
    {
        return $this->findOneBy(['clientId' => $clientId]);
    }

    /**
     * Supprime les clients dont plus rien ne dépend.
     *
     * Le seuil est celui de la durée de vie d'un refresh token, et ce n'est pas
     * un chiffre rond choisi au hasard : passé ce délai, aucune session ouverte
     * depuis ce client ne peut plus être renouvelée, donc plus rien ne s'appuie
     * sur sa ligne. Un client toujours actif, lui, voit `lastUsedAt` avancer à
     * chaque nouvelle autorisation.
     *
     * @return int le nombre de clients supprimés
     */
    public function deleteStale(int $ttl): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('COALESCE(c.lastUsedAt, c.createdAt) < :limite')
            ->setParameter('limite', new \DateTimeImmutable(sprintf('-%d seconds', $ttl)))
            ->getQuery()
            ->execute();
    }
}
