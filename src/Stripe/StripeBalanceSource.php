<?php

namespace Pr4w\Telemetry\Stripe;

use Pr4w\Telemetry\Contracts\BalanceSource;

/**
 * Reads the account balance from the Stripe API. One call per run.
 */
class StripeBalanceSource implements BalanceSource
{
    public function __construct(
        private readonly StripeClientFactory $clients,
    ) {
    }

    public function balance(): array
    {
        return $this->clients->make()->balance->retrieve()->toArray();
    }
}
