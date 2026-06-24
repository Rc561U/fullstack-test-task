<?php

namespace App\Service;

use App\Dto\RefundRequest;
use App\Entity\Refund;
use App\Entity\Transaction;
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

        // TX 1: validate, persist refund as PENDING, commit
        $connection->beginTransaction();
        try {
            $transaction = $this->transactions->findForRefundLocked($transactionId);

            if (null === $transaction) {
                throw TransactionNotFoundException::forId($transactionId);
            }

            $this->assertRefundable($transaction, $request->amount);

            $refund = Refund::createPending($transaction, $request->amount, $request->reason, $idempotencyKey->value);

            $this->em->persist($refund);
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

        // Outside TX: call provider
        try {
            $providerResult = $this->provider->refund(
                $transaction->getExternalId(),
                $request->amount,
                $transaction->getCurrency(),
            );

            if (empty($providerResult['providerRefundId'])) {
                throw new \RuntimeException('Provider returned no refundId');
            }
        } catch (\Throwable $e) {
            $this->markRefundFailed($refund);

            throw $e;
        }

        // TX 2: update refund status + transaction amounts
        $this->applyRefundAccepted($refund, $transaction, $providerResult['providerRefundId'], $request->amount);

        $this->em->refresh($transaction);
        $this->notifyMerchant($transaction, $request->amount);

        return new RefundResult($refund, true);
    }

    private function applyRefundAccepted(Refund $refund, Transaction $transaction, string $providerRefundId, string $amount): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $refund->setProviderRefundId($providerRefundId);
            $refund->setStatus(Refund::STATUS_ACCEPTED);

            $alreadyRefunded = $transaction->getRefundedAmount() ?? '0.00';
            $newRefundedAmount = bcadd($alreadyRefunded, $amount, 2);
            $transaction->setRefundedAmount($newRefundedAmount);
            $transaction->setStatus(
                bccomp($newRefundedAmount, $transaction->getAmount(), 2) >= 0
                    ? 'refunded'
                    : 'partially_refunded',
            );

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    private function notifyMerchant(Transaction $transaction, string $amount): void
    {
        $this->bus->dispatch(new MerchantNotification(
            $transaction->getMerchant()->getId(),
            sprintf(
                'Refund of %s %s accepted for transaction %d',
                $amount,
                $transaction->getCurrency(),
                $transaction->getId(),
            ),
        ));
    }

    private function markRefundFailed(Refund $refund): void
    {
        if (null === $refund->getId()) {
            return;
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $refund->setStatus(Refund::STATUS_FAILED);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable) {
            $connection->rollBack();
        }
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
