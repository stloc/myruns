<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Séries échantillonnées d'une activité (allure, FC, cadence, altitude), pour le profil de la page
 * détail. Table à part, chargée à la demande comme ActivityRoute ; remplie même sans GPS.
 */
#[ORM\Entity]
class ActivityStream
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<array{0: float, 1: int, 2: ?float, 3: ?int, 4: ?float}> $points distance (m), secondes
     *                                                                             depuis le départ, altitude (m), FC, cadence (cycles/min)
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
