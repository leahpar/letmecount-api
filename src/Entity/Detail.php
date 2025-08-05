<?php

namespace App\Entity;

use App\Repository\DetailRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity(repositoryClass: DetailRepository::class)]
class Detail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'details')]
        #[ORM\JoinColumn(nullable: false)]
        #[Ignore]
        public ?Depense $depense = null,

        #[ORM\ManyToOne(inversedBy: 'details')]
        #[ORM\JoinColumn(nullable: false)]
        #[Ignore]
        public ?User $user = null,

        #[ORM\Column(nullable: true)]
        public ?int $parts = null,

        #[ORM\Column]
        public ?float $montant = null,
    ) {}

}
