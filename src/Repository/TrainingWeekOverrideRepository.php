<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrainingWeekOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingWeekOverride>
 */
final class TrainingWeekOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingWeekOverride::class);
    }

    /**
     * @return array<string, TrainingWeekOverride> indexés par le lundi (Y-m-d), à partir de $since
     */
    public function findSinceIndexedByWeek(\DateTimeImmutable $since): array
    {
        /** @var list<TrainingWeekOverride> $overrides */
        $overrides = $this->createQueryBuilder('o')
            ->andWhere('o.weekStart >= :since')
            ->setParameter('since', $since, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($overrides as $override) {
            $indexed[$override->weekStart->format('Y-m-d')] = $override;
        }

        return $indexed;
    }

    public function findOneByWeek(\DateTimeImmutable $monday): ?TrainingWeekOverride
    {
        return $this->findOneBy(['weekStart' => $monday]);
    }

    /**
     * Enregistre l'ajustement, ou le supprime s'il ne s'écarte plus du réglage général.
     */
    public function store(TrainingWeekOverride $override): void
    {
        $em = $this->getEntityManager();
        if ($override->isEmpty()) {
            if (null !== $override->getId()) {
                $em->remove($override);
            }
        } else {
            $em->persist($override);
        }
        $em->flush();
    }
}
