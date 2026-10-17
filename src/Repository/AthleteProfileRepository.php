<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AthleteProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AthleteProfile>
 */
final class AthleteProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AthleteProfile::class);
    }

    /**
     * Le profil unique ; un profil vide (non enregistré) s'il n'a jamais été rempli.
     */
    public function current(): AthleteProfile
    {
        $profile = $this->createQueryBuilder('p')
            ->orderBy('p.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $profile instanceof AthleteProfile ? $profile : new AthleteProfile();
    }

    public function save(AthleteProfile $profile): void
    {
        $this->getEntityManager()->persist($profile);
        $this->getEntityManager()->flush();
    }
}
