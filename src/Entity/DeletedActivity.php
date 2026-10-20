<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Activité supprimée par l'utilisateur : les imports et la synchro ne la recréent pas
 * (même identifiant, ou même sortie venant d'une autre source, voir ActivityRepository::upsert()).
 */
#[ORM\Entity]
#[ORM\Index(columns: ['start_key'])]
class DeletedActivity
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    public private(set) string $id;

    #[ORM\Column(length: 20)]
    public private(set) string $source;

    /** Minute de départ UTC (AAAAMMJJHHMM), comme Activity::$startKey. */
    #[ORM\Column(length: 12)]
    public private(set) string $startKey;

    #[ORM\Column(length: 255)]
    public private(set) string $name;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    public private(set) \DateTimeImmutable $deletedAt;

    public function __construct(Activity $activity, \DateTimeImmutable $deletedAt = new \DateTimeImmutable())
    {
        $this->id = $activity->id;
        $this->source = $activity->source;
        $this->startKey = $activity->startKey;
        $this->name = $activity->name;
        $this->deletedAt = $deletedAt;
    }
}
