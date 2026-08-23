<?php

namespace Pr4w\Telemetry\Tests\Fixtures;

use Pr4w\Telemetry\Contracts\Collector;

class OtherStubCollector implements Collector
{
    /** @var array<string, mixed> */
    public static array $metrics = ['mrr_cents' => 1000];

    public function collect(): array
    {
        return static::$metrics;
    }
}
