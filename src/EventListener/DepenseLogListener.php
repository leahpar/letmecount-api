<?php

namespace App\EventListener;

use App\Entity\Depense;
use App\Entity\Log;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Depense::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: Depense::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: Depense::class)]
class DepenseLogListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security
    ) {
    }

    public function postPersist(Depense $depense, PostPersistEventArgs $args): void
    {
        $this->logAction('CREATE', $depense);
    }

    public function postUpdate(Depense $depense, PostUpdateEventArgs $args): void
    {
        $this->logAction('UPDATE', $depense);
    }

    public function preRemove(Depense $depense, PreRemoveEventArgs $args): void
    {
        $this->logAction('DELETE', $depense);
    }

    private function logAction(string $action, Depense $depense): void
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $log = new Log($action, $depense, $user);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
