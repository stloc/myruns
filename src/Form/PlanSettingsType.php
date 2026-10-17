<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AthleteProfile;
use App\Training\TrainingPlanBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Réglages du programme d'entraînement, enregistrés sur le profil.
 *
 * @extends AbstractType<AthleteProfile>
 */
final class PlanSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $sessions = range(TrainingPlanBuilder::MIN_SESSIONS, TrainingPlanBuilder::MAX_SESSIONS);
        // Garanti entier par configureOptions() ; OptionsResolver ne le dit pas au typage.
        $default = \is_int($options['default_sessions']) ? $options['default_sessions'] : TrainingPlanBuilder::MIN_SESSIONS;

        $builder
            ->add('planSessionsPerWeek', ChoiceType::class, [
                'label' => 'Sorties par semaine',
                'required' => false,
                'placeholder' => \sprintf('Automatique (%d)', $default),
                'choices' => array_combine(array_map('strval', $sessions), $sessions),
                'help' => 'Séance club comprise. Automatique : selon vos 4 dernières semaines.',
            ])
            ->add('clubWeekday', ChoiceType::class, [
                'label' => 'Séance club',
                'required' => false,
                'placeholder' => 'Pas de séance club',
                'choices' => array_flip(TrainingPlanBuilder::WEEKDAYS),
            ])
            ->add('clubTime', TimeType::class, [
                'label' => 'Heure',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'string',
                'input_format' => 'H:i',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AthleteProfile::class, 'default_sessions' => 3]);
        $resolver->setAllowedTypes('default_sessions', 'int');
    }
}
