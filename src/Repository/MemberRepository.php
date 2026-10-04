<?php

namespace Base\Office\Repository;

use Base\Office\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Member> */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Member::class);
    }

    /** @return list<Member> the team as the site shows it */
    public function findVisible(): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.visible = true')->andWhere('m.active = true')
            ->orderBy('m.position', 'ASC')->addOrderBy('m.displayName', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Member> those appointments can be booked with online */
    public function findBookable(): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.bookable = true')->andWhere('m.active = true')
            ->orderBy('m.position', 'ASC')->addOrderBy('m.displayName', 'ASC')
            ->getQuery()->getResult();
    }

    public function findOneVisibleBySlug(string $slug): ?Member
    {
        return $this->findOneBy(['slug' => $slug, 'visible' => true, 'active' => true]);
    }

    public function findOneByUser(?object $user): ?Member
    {
        return null === $user ? null : $this->findOneBy(['user' => $user]);
    }

}
