<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
// Les familles regroupent les tokens issus d'une même connexion, et chaque
// recherche de famille interroge cette colonne. La superclasse mappée du
// bundle ne peut pas déclarer l'index : il se pose ici.
#[ORM\Index(fields: ['family'])]
class RefreshToken extends BaseRefreshToken
{
}
