<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\Sport;
use App\Form\Model\ManualActivityRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ManualActivityRequest>
 */
final class ManualActivityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sport', EnumType::class, [
                'label' => 'Type',
                'class' => Sport::class,
                'choice_label' => static fn (Sport $sport): string => $sport->label(),
            ])
            ->add('startDate', DateTimeType::class, [
                'label' => 'Départ',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
            ])
            ->add('distanceKm', NumberType::class, [
                'label' => 'Distance (km)',
                'html5' => true,
                'scale' => 2,
                'attr' => ['min' => 0, 'step' => 'any', 'inputmode' => 'decimal'],
            ])
            ->add('duration', TextType::class, [
                'label' => 'Durée',
                'help' => '45:00, 1:45:00, 3h30…',
            ])
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('elevationGain', IntegerType::class, ['label' => 'D+ (m)', 'required' => false])
            ->add('averageHeartRate', IntegerType::class, ['label' => 'FC moyenne', 'required' => false])
            ->add('maxHeartRate', IntegerType::class, ['label' => 'FC max', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ManualActivityRequest::class]);
    }
}
