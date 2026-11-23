<?php

namespace Pr4w\Telemetry\Tests\Fixtures;

use Pr4w\Telemetry\Contracts\BalanceSource;

final class ArrayBalanceSource implements BalanceSource
{
    /**
     * @param  array<string, mixed>  $balance
     */
    public function __construct(private readonly array $balance)
    {
    }

    public function balance(): array
    {
        return $this->balance;
    }
}
