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

    #[ORM\ManyToOne(cascade: ['persist'], inversedBy: 'details')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Depense $depense = null;

    #[ORM\ManyToOne(inversedBy: 'details')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    public User $user;

    /**
     * Nombre de parts pour ce détail.<br>
     * Utilisé uniquement pour l'affichage dans le cas la répartition par parts.
     */
    #[ORM\Column]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    #[Assert\GreaterThanOrEqual(0)]
    public int $parts;

    /**
     * Montant réel en euros pour ce détail.<br>
     * Le montant est arrondi à deux décimales.<br>
     * Le montant est obligatoire peu importe le mode de répartition.
     */
    #[ORM\Column]
    #[Groups(['depense:read', 'depense:write'])]
    #[Assert\NotBlank]
    public float $montant;

}
