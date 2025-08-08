<?php

namespace App\Entity;

use App\Repository\DetailRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: DetailRepository::class)]
class Detail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'details')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Depense $depense = null;

    #[ORM\ManyToOne(inversedBy: 'details')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['depense:read', 'depense:write'])]
    public ?User $user = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['depense:read', 'depense:write'])]
    public ?int $parts = null;

    #[ORM\Column]
    #[Groups(['depense:read', 'depense:write'])]
    public ?float $montant = null;

}
