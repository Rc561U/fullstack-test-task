<?php

namespace App\Service;

use App\Dto\RefundRequest;
use App\Entity\Refund;
use App\Exception\RefundNotAllowedException;
use App\Exception\TransactionNotFoundException;
use App\Message\MerchantNotification;
use App\Repository\RefundRepository;
use App\Repository\TransactionRepository;
use App\Resolver\IdempotencyKey;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class RefundService
{
    private const REFUNDABLE_STATUSES = ['paid', 'settled', 'partially_refunded'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRepository $transactions,
        private readonly RefundRepository $refunds,
        private readonly ProviderClient $provider,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function refund(int $transactionId, RefundRequest $request, IdempotencyKey $idempotencyKey): RefundResult
    {
        $existing = $this->refunds->findByIdempotencyKey($idempotencyKey->value);
        if (null !== $existing) {
            return new RefundResult($existing, false);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $transaction = $this->transactions->findForRefundLocked($transactionId);

            if (null === $transaction) {
                throw TransactionNotFoundException::forId($transactionId);
            }

            $this->assertRefundable($transaction, $request->amount);

            $refund = new Refund();
            $refund->setTransaction($transaction);
            $refund->setAmount($request->amount);
            $refund->setReason($request->reason);
            $refund->setIdempotencyKey($idempotencyKey->value);
            $refund->setStatus(Refund::STATUS_PENDING);

            $this->em->persist($refund);
            $this->em->flush();

            $providerResult = $this->provider->refund(
                $transaction->getExternalId(),
                $request->amount,
                $transaction->getCurrency(),
            );

            $refund->setProviderRefundId($providerResult['providerRefundId'] ?? null);
            $refund->setStatus(Refund::STATUS_ACCEPTED);

            $newRefundedAmount = bcadd($transaction->getRefundedAmount(), $request->amount, 2);
            $transaction->setRefundedAmount($newRefundedAmount);
            $transaction->setStatus(
                bccomp($newRefundedAmount, $transaction->getAmount(), 2) >= 0
                    ? 'refunded'
                    : 'partially_refunded',
            );

            $this->em->flush();
            $connection->commit();
        } catch (UniqueConstraintViolationException $e) {
            $connection->rollBack();

            $existing = $this->refunds->findByIdempotencyKey($idempotencyKey->value);
            if (null !== $existing) {
                return new RefundResult($existing, false);
            }

            throw $e;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        $this->bus->dispatch(new MerchantNotification(
            $transaction->getMerchant()->getId(),
            sprintf(
                'Refund of %s %s accepted for transaction %d',
                $request->amount,
                $transaction->getCurrency(),
                $transaction->getId(),
            ),
        ));

        return new RefundResult($refund, true);
    }

    private function assertRefundable(Transaction $transaction, string $amount): void
    {
        if (!\in_array($transaction->getStatus(), self::REFUNDABLE_STATUSES, true)) {
            throw RefundNotAllowedException::invalidStatus($transaction->getId(), $transaction->getStatus());
        }

        if (null === $transaction->getExternalId() || '' === $transaction->getExternalId()) {
            throw RefundNotAllowedException::noExternalId($transaction->getId());
        }

        $refundable = bcsub($transaction->getAmount(), $transaction->getRefundedAmount(), 2);
        if (bccomp($amount, $refundable, 2) > 0) {
            throw RefundNotAllowedException::amountExceedsBalance($amount, $refundable);
        }
    }
}
