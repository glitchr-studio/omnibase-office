<?php

namespace Base\Office\Repository\Share;

use Base\Office\Entity\Share\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Document> */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /** @return list<Document> the documents kept for this user, newest first */
    public function findForRecipient(object $user, bool $withRevoked = false): array
    {
        $qb = $this->createQueryBuilder('d')->andWhere('d.recipient = :user')->setParameter('user', $user)->orderBy('d.createdAt', 'DESC');
        if (!$withRevoked) {
            $qb->andWhere('d.revokedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Document> the documents this user sent */
    public function findBySender(object $user, int $limit = 50): array
    {
        return $this->findBy(['sender' => $user], ['createdAt' => 'DESC'], $limit);
    }

    public function countUnread(object $user): int
    {
        return (int) $this->createQueryBuilder('d')->select('COUNT(d.id)')
            ->andWhere('d.recipient = :user')->setParameter('user', $user)
            ->andWhere('d.readAt IS NULL')->andWhere('d.revokedAt IS NULL')
            ->andWhere('(d.sender IS NULL OR d.sender != :user)')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<Document> expired or revoked before that date: their files go */
    public function findGoneBefore(\DateTimeInterface $before): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('(d.expiresAt IS NOT NULL AND d.expiresAt < :before) OR (d.revokedAt IS NOT NULL AND d.revokedAt < :before)')
            ->setParameter('before', \Base\Database\Type\Utc::from($before))
            ->getQuery()->getResult();
    }

}
