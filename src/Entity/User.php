<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Provider\CurrentUserProvider;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as JMS;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/users/{id}', requirements: ['id' => '\d+']),
        new Get(uriTemplate: '/users/me', provider: CurrentUserProvider::class),
        new GetCollection()
    ],
    normalizationContext: ['groups' => ['user:read']]
)]
#[ApiFilter(SearchFilter::class, properties: ['username' => 'partial'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use UserSecurityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read'])]
    public ?int $id = null;

    /**
     * @var Collection<int, Detail>
     */
    #[ORM\OneToMany(targetEntity: Detail::class, mappedBy: 'user', orphanRemoval: true)]
    #[Ignore]
    public Collection $details;

    /**
     * @var Collection<int, Depense>
     */
    #[ORM\OneToMany(targetEntity: Depense::class, mappedBy: 'payePar', orphanRemoval: true)]
    #[Ignore]
    public Collection $depenses;

    /**
     * Retourne le solde de l'utilisateur.
     * C'est-à-dire la somme des montants de ses détails.
     */
    #[JMS\VirtualProperty('solde')]
    #[Groups(['user:read'])]
    public function getSolde(): float
    {
        $solde = 0.0;
        foreach ($this->depenses as $depense) {
            $solde += $depense->montant;
        }
        foreach ($this->details as $detail) {
            $solde -= $detail->montant;
        }
        return $solde;
    }

}
