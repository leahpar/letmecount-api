<?php

namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Attribute\Groups;

class UpdateCredentialsDto
{
    #[Assert\NotBlank(message: 'Le nom d\'utilisateur est requis')]
    #[Assert\Length(max: 180)]
    #[Groups(['user:credentials'])]
    public ?string $username = null;
}
