<?php

namespace App\Form;

use App\Entity\Depense;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DepenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('montant', NumberType::class, [
                'required' => true,
            ])
            ->add('titre', TextType::class, [
                'required' => true,
            ])
            ->add('partage', ChoiceType::class, [
                'choices' => [
                    'Parts' => 'parts',
                    'Montants' => 'montants',
                ],
                'required' => true,
            ])
            ->add('details', CollectionType::class, [
                'entry_type' => DetailType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Depense::class,
        ]);
    }
}