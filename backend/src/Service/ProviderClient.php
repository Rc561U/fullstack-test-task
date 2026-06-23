<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class ProviderClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {
    }

    public function refund(string $externalId, string $amount, string $currency): array
    {
        $this->logger->info(sprintf(
            'Provider refund request externalId=%s amount=%s %s',
            $externalId,
            $amount,
            $currency,
        ));

        return [
            'status' => 'accepted',
            'providerRefundId' => 'rf_'.substr(md5($externalId.$amount), 0, 12),
        ];
    }
}
