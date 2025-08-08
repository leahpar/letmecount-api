<?php

namespace App\Tests\Validator;

use App\Entity\Depense;
use App\Entity\Detail;
use App\Entity\User;
use App\Validator\DepenseConstraint;
use App\Validator\DepenseConstraintValidator;
use DateTime;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

class DepenseMontantCoherentValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): DepenseConstraintValidator
    {
        return new DepenseConstraintValidator();
    }

    public function testValidDepense(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $depense = new Depense();
        $depense->date = new DateTime();
        $depense->montant = 100.00;
        $depense->titre = 'Test';
        $depense->partage = 'parts';

        $detail1 = new Detail();
        $detail1->user = $user;
        $detail1->montant = 60.00;
        $detail1->parts = 1;
        $depense->addDetail($detail1);

        $detail2 = new Detail();
        $detail2->user = $user;
        $detail2->montant = 40.00;
        $detail2->parts = 1;
        $depense->addDetail($detail2);

        $this->validator->validate($depense, new DepenseConstraint());

        $this->assertNoViolation();
    }

    public function testInvalidDepense(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $depense = new Depense();
        $depense->date = new DateTime();
        $depense->montant = 100.00;
        $depense->titre = 'Test';
        $depense->partage = 'parts';

        $detail1 = new Detail();
        $detail1->user = $user;
        $detail1->montant = 50.00; // Total des détails = 50, mais dépense = 100
        $detail1->parts = 1;
        $depense->addDetail($detail1);

        $constraint = new DepenseConstraint();
        $this->validator->validate($depense, $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ depenseMontant }}', '100')
            ->setParameter('{{ detailsSum }}', '50')
            ->assertRaised();
    }

    public function testDepenseWithSmallDifference(): void
    {
        $user = new User();
        $user->setUsername('testuser');

        $depense = new Depense();
        $depense->date = new DateTime();
        $depense->montant = 100.00;
        $depense->titre = 'Test';
        $depense->partage = 'parts';

        $detail1 = new Detail();
        $detail1->user = $user;
        $detail1->montant = 99.99; // Différence de 0.01 (acceptable)
        $detail1->parts = 1;
        $depense->addDetail($detail1);

        $this->validator->validate($depense, new DepenseConstraint());

        $this->assertNoViolation();
    }
}
