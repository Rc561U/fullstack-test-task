<?php

namespace App\Tests\Unit\Service;

use App\Dto\RefundRequest;
use App\Entity\Merchant;
use App\Entity\Refund;
use App\Entity\Transaction;
use App\Exception\RefundNotAllowedException;
use App\Exception\TransactionNotFoundException;
use App\Message\MerchantNotification;
use App\Repository\RefundRepository;
use App\Repository\TransactionRepository;
use App\Resolver\IdempotencyKey;
use App\Service\ProviderClient;
use App\Service\RefundService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class RefundServiceTest extends TestCase
{
    private EntityManagerInterface&Stub $em;
    private TransactionRepository&Stub $transactions;
    private RefundRepository&Stub $refunds;
    private ProviderClient&Stub $provider;
    private MessageBusInterface&Stub $bus;
    private Connection&Stub $connection;
    private RefundService $service;

    protected function setUp(): void
    {
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->transactions = $this->createStub(TransactionRepository::class);
        $this->refunds = $this->createStub(RefundRepository::class);
        $this->provider = $this->createStub(ProviderClient::class);
        $this->bus = $this->createStub(MessageBusInterface::class);
        $this->connection = $this->createStub(Connection::class);

        $this->em->method('getConnection')->willReturn($this->connection);

        $this->service = new RefundService(
            $this->em,
            $this->transactions,
            $this->refunds,
            $this->provider,
            $this->bus,
        );
    }

    #[Test]
    public function returnsExistingRefundForDuplicateIdempotencyKey(): void
    {
        $existingRefund = $this->createRefund(1, Refund::STATUS_ACCEPTED);
        $idempotencyKey = new IdempotencyKey('duplicate-key');
        $request = new RefundRequest('50.00', 'Customer request');

        $refundsMock = $this->createMock(RefundRepository::class);
        $refundsMock
            ->expects($this->once())
            ->method('findByIdempotencyKey')
            ->with('duplicate-key')
            ->willReturn($existingRefund);

        $service = new RefundService(
            $this->em,
            $this->transactions,
            $refundsMock,
            $this->provider,
            $this->bus,
        );

        $result = $service->refund(1, $request, $idempotencyKey);

        $this->assertFalse($result->created);
        $this->assertSame($existingRefund, $result->refund);
    }

    #[Test]
    public function throwsExceptionWhenTransactionNotFound(): void
    {
        $idempotencyKey = new IdempotencyKey('new-key');
        $request = new RefundRequest('50.00', 'Customer request');

        $transactionsMock = $this->createMock(TransactionRepository::class);
        $transactionsMock
            ->expects($this->once())
            ->method('findForRefundLocked')
            ->with(999)
            ->willReturn(null);

        $service = new RefundService(
            $this->em,
            $transactionsMock,
            $this->refunds,
            $this->provider,
            $this->bus,
        );

        $this->expectException(TransactionNotFoundException::class);
        $this->expectExceptionMessage('Transaction 999 not found.');

        $service->refund(999, $request, $idempotencyKey);
    }

    #[Test]
    public function throwsExceptionWhenTransactionStatusNotRefundable(): void
    {
        $transaction = $this->createTransaction(1, 'failed', '100.00', '0.00', 'ext_123');
        $idempotencyKey = new IdempotencyKey('new-key');
        $request = new RefundRequest('50.00', 'Customer request');

        $this->transactions->method('findForRefundLocked')->willReturn($transaction);

        $this->expectException(RefundNotAllowedException::class);
        $this->expectExceptionMessage('Transaction 1 cannot be refunded in status "failed".');

        $this->service->refund(1, $request, $idempotencyKey);
    }

    #[Test]
    public function throwsExceptionWhenTransactionHasNoExternalId(): void
    {
        $transaction = $this->createTransaction(1, 'paid', '100.00', '0.00', null);
        $idempotencyKey = new IdempotencyKey('new-key');
        $request = new RefundRequest('50.00', 'Customer request');

        $this->transactions->method('findForRefundLocked')->willReturn($transaction);

        $this->expectException(RefundNotAllowedException::class);
        $this->expectExceptionMessage('Transaction 1 has no external provider reference.');

        $this->service->refund(1, $request, $idempotencyKey);
    }

    #[Test]
    public function throwsExceptionWhenRefundAmountExceedsBalance(): void
    {
        $transaction = $this->createTransaction(1, 'paid', '100.00', '60.00', 'ext_123');
        $idempotencyKey = new IdempotencyKey('new-key');
        $request = new RefundRequest('50.00', 'Customer request');

        $this->transactions->method('findForRefundLocked')->willReturn($transaction);

        $this->expectException(RefundNotAllowedException::class);
        $this->expectExceptionMessage('Refund amount 50.00 exceeds refundable balance 40.00.');

        $this->service->refund(1, $request, $idempotencyKey);
    }

    #[Test]
    public function successfulFullRefund(): void
    {
        $merchant = $this->createMerchant(10);
        $transaction = $this->createTransaction(1, 'paid', '100.00', '0.00', 'ext_123', $merchant);
        $idempotencyKey = new IdempotencyKey('new-key');
        $request = new RefundRequest('100.00', 'Full refund');

        $emMock = $this->createMock(EntityManagerInterface::class);
        $emMock->method('getConnection')->willReturn($this->connection);
        $emMock
            ->expects($this->once())
            ->method('persist');
        $emMock
            ->expects($this->exactly(2))
            ->method('flush');

        $providerMock = $this->createMock(ProviderClient::class);
        $providerMock
            ->expects($this->once())
            ->method('refund')
            ->with('ext_123', '100.00', 'EUR')
            ->willReturn(['providerRefundId' => 'rf_12345']);

        $busMock = $this->createMock(MessageBusInterface::class);
        $busMock
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(MerchantNotification::class))
            ->willReturn(new Envelope(new MerchantNotification(10, 'test')));

        $this->transactions->method('findForRefundLocked')->willReturn($transaction);

        $service = new RefundService(
            $emMock,
            $this->transactions,
            $this->refunds,
            $providerMock,
            $busMock,
        );

        $result = $service->refund(1, $request, $idempotencyKey);

        $this->assertTrue($result->created);
        $this->assertSame(Refund::STATUS_ACCEPTED, $result->refund->getStatus());
        $this->assertSame('rf_12345', $result->refund->getProviderRefundId());
        $this->assertSame('100.00', $transaction->getRefundedAmount());
        $this->assertSame('refunded', $transaction->getStatus());
    }

    #[Test]
    public function successfulPartialRefund(): void
    {
        $merchant = $this->createMerchant(10);
        $transaction = $this->createTransaction(1, 'paid', '100.00', '0.00', 'ext_123', $merchant);
        $idempotencyKey = new IdempotencyKey('new-key');
        $request = new RefundRequest('40.00', 'Partial refund');

        $this->transactions->method('findForRefundLocked')->willReturn($transaction);
        $this->provider->method('refund')->willReturn(['providerRefundId' => 'rf_12345']);
        $this->bus->method('dispatch')->willReturn(new Envelope(new MerchantNotification(10, 'test')));

        $result = $this->service->refund(1, $request, $idempotencyKey);

        $this->assertTrue($result->created);
        $this->assertSame('40.00', $transaction->getRefundedAmount());
        $this->assertSame('partially_refunded', $transaction->getStatus());
    }

    #[Test]
    public function marksRefundAsFailedWhenProviderThrows(): void
    {
        $transaction = $this->createTransaction(1, 'paid', '100.00', '0.00', 'ext_123');
        $idempotencyKey = new IdempotencyKey('new-key');
        $request = new RefundRequest('50.00', 'Customer request');

        $this->transactions->method('findForRefundLocked')->willReturn($transaction);

        $providerMock = $this->createMock(ProviderClient::class);
        $providerMock
            ->expects($this->once())
            ->method('refund')
            ->willThrowException(new \RuntimeException('Provider error'));

        $service = new RefundService(
            $this->em,
            $this->transactions,
            $this->refunds,
            $providerMock,
            $this->bus,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider error');

        $service->refund(1, $request, $idempotencyKey);
    }

    #[Test]
    public function throwsExceptionWhenProviderReturnsNoRefundId(): void
    {
        $transaction = $this->createTransaction(1, 'paid', '100.00', '0.00', 'ext_123');
        $idempotencyKey = new IdempotencyKey('new-key');
        $request = new RefundRequest('50.00', 'Customer request');

        $this->transactions->method('findForRefundLocked')->willReturn($transaction);
        $this->provider->method('refund')->willReturn(['providerRefundId' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider returned no refundId');

        $this->service->refund(1, $request, $idempotencyKey);
    }

    private function createMerchant(int $id): Merchant
    {
        $merchant = new Merchant();
        $reflection = new \ReflectionClass($merchant);
        $property = $reflection->getProperty('id');
        $property->setValue($merchant, $id);

        return $merchant;
    }

    private function createTransaction(
        int $id,
        string $status,
        string $amount,
        string $refundedAmount,
        ?string $externalId,
        ?Merchant $merchant = null,
    ): Transaction {
        $transaction = new Transaction();
        $reflection = new \ReflectionClass($transaction);

        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($transaction, $id);

        $transaction->setStatus($status);
        $transaction->setAmount($amount);
        $transaction->setRefundedAmount($refundedAmount);
        $transaction->setExternalId($externalId);
        $transaction->setCurrency('EUR');

        if (null !== $merchant) {
            $transaction->setMerchant($merchant);
        }

        return $transaction;
    }

    private function createRefund(int $id, string $status): Refund
    {
        $refund = new Refund();
        $reflection = new \ReflectionClass($refund);
        $property = $reflection->getProperty('id');
        $property->setValue($refund, $id);
        $refund->setStatus($status);

        return $refund;
    }
}
