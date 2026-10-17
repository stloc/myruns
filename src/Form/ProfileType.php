<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AthleteProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AthleteProfile>
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inferred = \is_int($options['inferred_running_since']) ? $options['inferred_running_since'] : null;

        $builder
            ->add('maxHeartRate', IntegerType::class, [
                'label' => 'FC max (bpm)',
                'required' => false,
                'help' => 'Mesurée en fin de course ou de test d\'effort. À défaut, laisser vide (la formule 220 − âge est trop imprécise).',
            ])
            ->add('restingHeartRate', IntegerType::class, [
                'label' => 'FC de repos (bpm)',
                'required' => false,
                'help' => 'Au réveil, allongé. Améliore l\'estimation cardiaque.',
            ])
            ->add('birthYear', IntegerType::class, [
                'label' => 'Année de naissance',
                'required' => false,
                'help' => 'La progression ralentit avec l\'âge.',
            ])
            ->add('runningSinceYear', IntegerType::class, [
                'label' => 'Je cours régulièrement depuis',
                'required' => false,
                'attr' => null === $inferred ? [] : ['placeholder' => (string) $inferred],
                'help' => null === $inferred
                    ? 'Année. Un débutant progresse plus vite.'
                    : \sprintf('Année. Vide : d\'après votre historique, au moins depuis %d. Indiquez l\'année réelle si vous couriez avant.', $inferred),
            ])
            ->add('weightKg', NumberType::class, [
                'label' => 'Poids actuel (kg)',
                'required' => false,
                'scale' => 1,
            ])
            ->add('targetWeightKg', NumberType::class, [
                'label' => 'Poids visé le jour de l\'objectif (kg)',
                'required' => false,
                'scale' => 1,
                'help' => 'Seulement si une perte ou une prise de poids est prévue. Effet plafonné à ±3 %.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AthleteProfile::class, 'inferred_running_since' => null]);
        $resolver->setAllowedTypes('inferred_running_since', ['null', 'int']);
    }
}
