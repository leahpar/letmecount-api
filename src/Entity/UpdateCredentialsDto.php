<?php

namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Attribute\Groups;

class UpdateCredentialsDto
{
    #[Assert\NotBlank(message: 'Le token est requis')]
    #[Groups(['user:credentials'])]
    public ?string $token = null;

    #[Groups(['user:credentials'])]
    public ?string $username = null;

    #[Groups(['user:credentials'])]
    public ?string $password = null;
}
