<?php

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Merchant $merchant;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $fee = '0.00';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 4)]
    private string $feeRate = '0.0000';

    #[ORM\Column(length: 20)]
    private string $status = 'paid';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $refundedAmount = '0.00';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Refund> */
    #[ORM\OneToMany(targetEntity: Refund::class, mappedBy: 'transaction')]
    private Collection $refunds;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->refunds = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMerchant(): Merchant
    {
        return $this->merchant;
    }

    public function setMerchant(Merchant $merchant): self
    {
        $this->merchant = $merchant;

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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getFee(): string
    {
        return $this->fee;
    }

    public function setFee(string $fee): self
    {
        $this->fee = $fee;

        return $this;
    }

    public function getFeeRate(): string
    {
        return $this->feeRate;
    }

    public function setFeeRate(string $feeRate): self
    {
        $this->feeRate = $feeRate;

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

    public function getRefundedAmount(): string
    {
        return $this->refundedAmount;
    }

    public function setRefundedAmount(string $refundedAmount): self
    {
        $this->refundedAmount = $refundedAmount;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Refund>
     */
    public function getRefunds(): Collection
    {
        return $this->refunds;
    }
}
