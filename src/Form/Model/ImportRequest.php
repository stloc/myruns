<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ImportRequest
{
    /** Extensions acceptées, testées sur la fin du nom d'origine (les doubles extensions d'abord). */
    private const EXTENSIONS = ['gpx.gz', 'tcx.gz', 'zip', 'gpx', 'tcx'];

    #[Assert\NotNull(message: 'Choisissez un fichier.')]
    #[Assert\File(maxSize: '1024M', maxSizeMessage: 'Fichier trop volumineux ({{ size }} {{ suffix }}, maximum {{ limit }} {{ suffix }}).')]
    public ?UploadedFile $file = null;

    /**
     * Extension sûre déduite du nom d'origine, ou null si le fichier n'est pas pris en charge.
     */
    public function extension(): ?string
    {
        $name = strtolower($this->file?->getClientOriginalName() ?? '');
        foreach (self::EXTENSIONS as $extension) {
            if (str_ends_with($name, '.'.$extension)) {
                return $extension;
            }
        }

        return null;
    }

    #[Assert\Callback]
    public function validateExtension(ExecutionContextInterface $context): void
    {
        if (null !== $this->file && null === $this->extension()) {
            $context->buildViolation('Formats acceptés : archive Strava .zip, .gpx, .tcx (éventuellement .gz).')
                ->atPath('file')
                ->addViolation();
        }
    }
}
