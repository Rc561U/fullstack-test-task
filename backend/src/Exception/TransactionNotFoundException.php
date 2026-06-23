<?php

namespace App\Exception;

final class TransactionNotFoundException extends \RuntimeException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Transaction %d not found.', $id));
    }
}
