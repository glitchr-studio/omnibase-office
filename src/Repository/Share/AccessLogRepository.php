<?php

namespace Base\Office\Repository\Share;

use Base\Office\Entity\Share\AccessLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccessLog> */
class AccessLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessLog::class);
    }

    /** @return list<AccessLog> newest first */
    public function findLatest(int $limit = 100, ?int $documentId = null, ?object $user = null): array
    {
        $qb = $this->createQueryBuilder('l')->orderBy('l.createdAt', 'DESC')->addOrderBy('l.id', 'DESC')->setMaxResults($limit);
        if (null !== $documentId) {
            $qb->andWhere('l.documentId = :document')->setParameter('document', $documentId);
        }
        if (null !== $user) {
            $qb->andWhere('l.user = :user')->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<AccessLog> what was done with this person's documents */
    public function findForRecipient(int $recipientId, int $limit = 200): array
    {
        return $this->findBy(['recipientId' => $recipientId], ['createdAt' => 'DESC'], $limit);
    }

    public function purgeBefore(\DateTimeInterface $before): int
    {
        return $this->createQueryBuilder('l')->delete()->andWhere('l.createdAt < :before')->setParameter('before', \Base\Office\Database\Utc::of($before))->getQuery()->execute();
    }

}
