<?php

namespace Pr4w\Telemetry\Exceptions;

class CollectorException extends TelemetryException
{
    public static function duplicateMetric(string $metric, string $collector): self
    {
        return new self("Collector [{$collector}] returned metric [{$metric}], which another collector already produced. Metric names must be unique across collectors.");
    }

    public static function notACollector(string $class): self
    {
        return new self("Configured collector [{$class}] does not implement ".\Pr4w\Telemetry\Contracts\Collector::class.'.');
    }
}
