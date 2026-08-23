<?php

namespace Pr4w\Telemetry\Tests\Fixtures;

use Pr4w\Telemetry\Contracts\Collector;
use RuntimeException;

class ExplodingCollector implements Collector
{
    public function collect(): array
    {
        throw new RuntimeException('the database went away');
    }
}
