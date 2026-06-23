<?php

namespace App\Exception;

final class RefundNotAllowedException extends \RuntimeException
{
    public static function invalidStatus(int $transactionId, string $status): self
    {
        return new self(sprintf(
            'Transaction %d cannot be refunded in status "%s".',
            $transactionId,
            $status,
        ));
    }

    public static function noExternalId(int $transactionId): self
    {
        return new self(sprintf(
            'Transaction %d has no external provider reference.',
            $transactionId,
        ));
    }

    public static function amountExceedsBalance(string $amount, string $refundable): self
    {
        return new self(sprintf(
            'Refund amount %s exceeds refundable balance %s.',
            $amount,
            $refundable,
        ));
    }
}
