<?php

namespace Pr4w\Telemetry\Tests\Fixtures;

use Pr4w\Telemetry\Contracts\Collector;

class StubCollector implements Collector
{
    /** @var array<string, mixed> */
    public static array $metrics = ['total_users' => 42];

    public function collect(): array
    {
        return static::$metrics;
    }
}
