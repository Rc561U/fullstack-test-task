<?php

namespace App\Repository;

use App\Entity\Refund;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Refund>
 */
class RefundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Refund::class);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?Refund
    {
        return $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }
}
