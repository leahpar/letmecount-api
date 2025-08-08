<?php

namespace App\Validator;

use App\Entity\Depense;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class DepenseConstraintValidator extends ConstraintValidator
{
    /**
     * @param Depense $value The Depense entity to validate.
     * @param DepenseConstraint $constraint The constraint for validation.
     */
    public function validate(mixed $value, Constraint $constraint): void
    {

        $sommeDetails = 0.0;
        foreach ($value->details as $detail) {
            $sommeDetails += $detail->montant ?? 0.0;
        }

        if (abs($value->montant - $sommeDetails) >= 0.02) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ depenseMontant }}', (string) $value->montant)
                ->setParameter('{{ detailsSum }}', (string) $sommeDetails)
                ->addViolation();
        }
    }
}
