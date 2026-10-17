<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\AthleteProfile;
use App\Prediction\Goal;
use App\Util\Units;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Saisie du formulaire d'objectif ; les formats acceptés sont ceux de Units.
 */
final class GoalRequest
{
    #[Assert\NotBlank(message: 'Indiquez une distance.')]
    public string $distance = '10k';

    #[Assert\NotBlank(message: 'Indiquez un temps visé.')]
    public string $time = '';

    #[Assert\NotNull(message: 'Indiquez une date.')]
    #[Assert\GreaterThan('today', message: 'La date de l\'objectif doit être dans le futur.')]
    public ?\DateTimeImmutable $date = null;

    /**
     * Pré-remplit avec le dernier objectif enregistré ; une date passée est remplacée par la date par défaut.
     */
    public static function fromProfile(AthleteProfile $profile): self
    {
        $request = new self();
        $request->distance = $profile->lastGoalDistance ?? $request->distance;
        $request->time = $profile->lastGoalTime ?? $request->time;
        $today = new \DateTimeImmutable('today');
        $request->date = null !== $profile->lastGoalDate && $profile->lastGoalDate > $today
            ? $profile->lastGoalDate
            : $today->modify('+10 weeks');

        return $request;
    }

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

    public function toGoal(): Goal
    {
        if (null === $this->date) {
            throw new \LogicException('Objectif incomplet : la saisie doit être validée avant.');
        }

        return new Goal(Units::parseDistance($this->distance), Units::parseDuration($this->time), $this->date);
    }
}
