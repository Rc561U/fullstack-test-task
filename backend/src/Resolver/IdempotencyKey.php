<?php

namespace App\Resolver;

final readonly class IdempotencyKey
{
    public function __construct(public string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
