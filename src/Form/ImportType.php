<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\ImportRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ImportRequest>
 */
final class ImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'Archive Strava ou trace',
            'help' => 'export_XXXX.zip reçu par e-mail, ou fichier .gpx / .tcx',
            'attr' => ['accept' => '.zip,.gpx,.tcx,.gz'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ImportRequest::class]);
    }
}
