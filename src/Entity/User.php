<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use UserSecurityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * @var Collection<int, Detail>
     */
    #[ORM\OneToMany(targetEntity: Detail::class, mappedBy: 'user', orphanRemoval: true)]
    public Collection $details;


    /**
     * Retourne le solde de l'utilisateur.
     * C'est-à-dire la somme des montants de ses détails.
     */
    public function getSolde(): float
    {
        $solde = 0.0;
        foreach ($this->details as $detail) {
            $solde += $detail->montant ?? 0.0;
        }
        return $solde;
    }

}
