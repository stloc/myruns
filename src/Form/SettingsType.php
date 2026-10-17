<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Clés d'API : un champ laissé vide conserve la clé déjà enregistrée.
 *
 * @extends AbstractType<array<string, ?string>>
 */
final class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('INTERVALS_API_KEY', PasswordType::class, [
                'label' => 'Clé API intervals.icu',
                'help' => 'Sur intervals.icu : Paramètres → tout en bas, « Developer Settings » → API Key.',
                'required' => false,
                'constraints' => [new Assert\Length(max: 200)],
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('ANTHROPIC_API_KEY', PasswordType::class, [
                'label' => 'Clé API Anthropic (facultative)',
                'help' => 'Pour l\'analyse IA de l\'objectif. Sans clé, la prédiction fonctionne quand même.',
                'required' => false,
                'constraints' => [new Assert\Length(max: 200)],
                'attr' => ['autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['empty_data' => []]);
    }
}
