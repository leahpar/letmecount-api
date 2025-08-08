<?php

namespace App\Entity;

use App\Repository\DetailRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DetailRepository::class)]
class Detail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'details', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    public ?Depense $depense = null;

    #[ORM\ManyToOne(inversedBy: 'details')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    public User $user;

    #[ORM\Column]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    public int $parts;

    #[ORM\Column]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    public float $montant;

}
