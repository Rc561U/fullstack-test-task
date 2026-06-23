<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class RefundRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Amount is required.')]
        #[Assert\Regex(
            pattern: '/^\d+\.\d{2}$/',
            message: 'Amount must be a decimal string with exactly 2 fraction digits (e.g. "10.00").',
        )]
        public readonly string $amount,

        #[Assert\NotBlank(message: 'Reason is required.')]
        #[Assert\Length(
            min: 3,
            max: 500,
            minMessage: 'Reason must be at least {{ limit }} characters.',
            maxMessage: 'Reason cannot exceed {{ limit }} characters.',
        )]
        public readonly string $reason,
    ) {
    }

    #[Assert\Callback]
    public function validateAmountIsPositive(ExecutionContextInterface $context): void
    {
        if (!preg_match('/^\d+\.\d{2}$/', $this->amount)) {
            return;
        }

        if ((float) $this->amount <= 0) {
            $context->buildViolation('Amount must be greater than zero.')
                ->atPath('amount')
                ->addViolation();
        }
    }
}
