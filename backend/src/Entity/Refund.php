<?php

namespace App\Entity;

use App\Repository\RefundRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RefundRepository::class)]
#[ORM\Table(name: 'refund')]
#[ORM\UniqueConstraint(name: 'uniq_refund_idempotency_key', columns: ['idempotency_key'])]
class Refund
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'refunds')]
    #[ORM\JoinColumn(nullable: false)]
    private Transaction $transaction;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 500)]
    private string $reason = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $providerRefundId = null;

    #[ORM\Column(length: 64)]
    private string $idempotencyKey = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }

    public function setTransaction(Transaction $transaction): self
    {
        $this->transaction = $transaction;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getProviderRefundId(): ?string
    {
        return $this->providerRefundId;
    }

    public function setProviderRefundId(?string $providerRefundId): self
    {
        $this->providerRefundId = $providerRefundId;

        return $this;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(string $idempotencyKey): self
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
