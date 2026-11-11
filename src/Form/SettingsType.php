<?php

declare(strict_types=1);

namespace App\Form;

use App\Settings\LocalSettings;
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
    private const array FIELDS = [
        'INTERVALS_API_KEY' => [
            'label' => 'Clé API intervals.icu',
            'help' => 'Sur intervals.icu : Paramètres → tout en bas, « Developer Settings » → API Key.',
        ],
        'ANTHROPIC_API_KEY' => [
            'label' => 'Clé API Anthropic (facultative)',
            'help' => 'Pour l\'analyse IA de l\'objectif. Sans clé, la prédiction fonctionne quand même.',
        ],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (self::FIELDS as $key => $field) {
            if (!self::isVisible($key)) {
                continue;
            }
            $builder->add($key, PasswordType::class, $field + [
                'required' => false,
                'constraints' => [new Assert\Length(max: 200)],
                'attr' => ['autocomplete' => 'off'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['empty_data' => []]);
    }

    /** Champs proposés sur la page : voir LocalSettings::VISIBLE_KEYS. */
    private static function isVisible(string $key): bool
    {
        return [] !== array_intersect([$key], LocalSettings::VISIBLE_KEYS);
    }
}
