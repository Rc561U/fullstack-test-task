<?php

namespace App\Service;

use App\Entity\Refund;

final readonly class RefundResult
{
    public function __construct(
        public Refund $refund,
        public bool $created,
    ) {
    }
}
