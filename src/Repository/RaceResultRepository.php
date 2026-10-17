<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RaceResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceResult>
 */
final class RaceResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceResult::class);
    }

    /**
     * @return list<RaceResult> de la plus récente à la plus ancienne
     */
    public function findLatestFirst(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.date', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<RaceResult> courses courues entre les deux dates (incluses)
     */
    public function findBetween(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.date >= :since')
            ->andWhere('r.date <= :until')
            ->setParameter('since', $since, Types::DATE_IMMUTABLE)
            ->setParameter('until', $until, Types::DATE_IMMUTABLE)
            ->orderBy('r.date', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    public function save(RaceResult $race): void
    {
        $this->getEntityManager()->persist($race);
        $this->getEntityManager()->flush();
    }

    public function delete(RaceResult $race): void
    {
        $this->getEntityManager()->remove($race);
        $this->getEntityManager()->flush();
    }
}
