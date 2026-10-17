<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace GPS simplifiée d'une activité, pour la carte de la page détail.
 *
 * Table à part (chargée à la demande) : les listes d'activités ne lisent jamais ces points.
 */
#[ORM\Entity]
class ActivityRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: int}> $points latitude, longitude,
     *                                                                  distance (m), secondes depuis le départ
     */
    public function __construct(
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $points,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
