<?php

namespace Pr4w\Telemetry\Contracts;

interface BalanceSource
{
    /**
     * The account balance in Stripe's decoded shape:
     * ['available' => [['amount' => int, 'currency' => string], ...], 'pending' => [...]].
     *
     * @return array<string, mixed>
     */
    public function balance(): array;
}
