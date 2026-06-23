<?php

namespace App\Service;

use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;

class BalanceService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function applyPayment(Transaction $tx): void
    {
        $merchant = $tx->getMerchant();

        $net = bcsub($tx->getAmount(), $tx->getFee(), 2);
        $merchant->setBalance(bcadd($merchant->getBalance(), $net, 2));
        $this->em->flush();

        $tx->setStatus('settled');
        $this->em->flush();
    }
}
