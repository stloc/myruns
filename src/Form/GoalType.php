<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\GoalRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<GoalRequest>
 */
final class GoalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('distance', TextType::class, [
                'label' => 'Distance',
                'help' => '5k, 10, 21.1, semi, marathon…',
            ])
            ->add('time', TextType::class, [
                'label' => 'Temps visé',
                'help' => '45:00, 1:45:00, 3h30…',
                'attr' => ['placeholder' => '45:00'],
            ])
            ->add('date', DateType::class, [
                'label' => 'Date de l\'objectif',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GoalRequest::class]);
    }
}
