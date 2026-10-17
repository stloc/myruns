<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\RaceResult;
use App\Util\Units;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class RaceRequest
{
    #[Assert\NotNull(message: 'Indiquez la date de la course.')]
    #[Assert\LessThanOrEqual('today', message: 'La course doit déjà avoir eu lieu.')]
    public ?\DateTimeImmutable $date = null;

    #[Assert\NotBlank(message: 'Indiquez une distance.')]
    public string $distance = '';

    #[Assert\NotBlank(message: 'Indiquez votre temps.')]
    public string $time = '';

    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\Callback]
    public function validateFormats(ExecutionContextInterface $context): void
    {
        foreach (['distance' => Units::parseDistance(...), 'time' => Units::parseDuration(...)] as $field => $parser) {
            if ('' === trim($this->{$field})) {
                continue;
            }
            try {
                $parser($this->{$field});
            } catch (\InvalidArgumentException $e) {
                $context->buildViolation($e->getMessage())->atPath($field)->addViolation();
            }
        }
    }

    public function toRaceResult(): RaceResult
    {
        if (null === $this->date) {
            throw new \LogicException('Course incomplète : la saisie doit être validée avant.');
        }

        return new RaceResult($this->date, Units::parseDistance($this->distance), Units::parseDuration($this->time), trim($this->name));
    }
}
