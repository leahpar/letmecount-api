<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\LogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: LogRepository::class)]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['log:read']]),
        new GetCollection(
            order: ['date' => 'DESC'],
            normalizationContext: ['groups' => ['log:read']]
        )
    ]
)]
class Log
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['log:read'])]
    public ?int $id = null;

    #[ORM\Column]
    #[Groups(['log:read'])]
    public \DateTime $date;

    #[ORM\Column(length: 255)]
    #[Groups(['log:read'])]
    public string $action;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['log:read'])]
    public User $user;

    #[ORM\ManyToOne(targetEntity: Depense::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['log:read'])]
    public ?Depense $depense = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['log:read'])]
    public ?string $libelle = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['log:read'])]
    public ?float $montant = null;

    public function __construct(Depense $depense)
    {
        $this->date = new \DateTime();
        $this->depense = $depense;
        $this->libelle = $depense->titre;
        $this->montant = $depense->montant;
    }
}
