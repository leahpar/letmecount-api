<?php

namespace App\Repository;

use App\Entity\OAuthAuthorizationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthAuthorizationCode>
 */
class OAuthAuthorizationCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthAuthorizationCode::class);
    }

    public function findOneByCodeHash(string $codeHash): ?OAuthAuthorizationCode
    {
        return $this->findOneBy(['codeHash' => $codeHash]);
    }

    /**
     * Les codes expirés ne servent plus à rien : ils sont balayés à l'émission
     * du suivant, ce qui évite une tâche planifiée pour une table qui ne
     * contient jamais plus de quelques lignes.
     */
    public function deleteExpired(): void
    {
        $this->createQueryBuilder('c')
            ->delete()
            ->where('c.expiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
