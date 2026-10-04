<?php

namespace Base\Office\Repository;

use Base\Office\Entity\ContactRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContactRequest> */
class ContactRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactRequest::class);
    }

    /** @return list<ContactRequest> */
    public function findOpen(int $limit = 5): array
    {
        return $this->createQueryBuilder('c')->andWhere('c.handledAt IS NULL')->orderBy('c.createdAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('c')->select('COUNT(c.id)')->andWhere('c.handledAt IS NULL')->getQuery()->getSingleScalarResult();
    }

    public function purgeBefore(\DateTimeInterface $before): int
    {
        return $this->createQueryBuilder('c')->delete()->andWhere('c.createdAt < :before')->setParameter('before', \Base\Office\Database\Utc::of($before))->getQuery()->execute();
    }

}
