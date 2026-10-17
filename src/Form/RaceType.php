<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\RaceRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RaceRequest>
 */
final class RaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('distance', TextType::class, [
                'label' => 'Distance',
                'help' => '5k, 10, semi, marathon…',
            ])
            ->add('time', TextType::class, [
                'label' => 'Temps officiel',
                'attr' => ['placeholder' => '44:12'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nom de la course',
                'required' => false,
                'empty_data' => '',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RaceRequest::class]);
    }
}
