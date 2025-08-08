<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class DepenseConstraint extends Constraint
{
    public string $message = 'Le montant de la dépense ({{ depenseMontant }}) ne correspond pas à la somme des détails ({{ detailsSum }})';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
