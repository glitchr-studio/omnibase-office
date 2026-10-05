<?php

namespace Base\Office\Repository;

use Base\Office\Entity\Area;
use Base\Office\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * The fields of practice of a regime (Base\Office\Entity\Area): its
 * repository extends this one and names its entity.
 *
 *     class AreaRepository extends \Base\Office\Repository\AreaRepository
 *     {
 *         public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Area::class); }
 *     }
 *
 * @template T of Area
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class AreaRepository extends ServiceEntityRepository
{
    /** @return list<T> */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /** @return T|null */
    public function findOneActiveBySlug(string $slug): ?Area
    {
        return $this->findOneBy(['slug' => $slug, 'active' => true]);
    }

    /** @return list<T> the fields a member follows */
    public function findForMember(Member $member): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.members', 'm')->andWhere('m = :member')->setParameter('member', $member)
            ->andWhere('a.active = true')->orderBy('a.position', 'ASC')
            ->getQuery()->getResult();
    }
}
