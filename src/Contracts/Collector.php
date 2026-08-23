<?php

namespace Pr4w\Telemetry\Contracts;

interface Collector
{
    /**
     * Return a flat map of metric name => value, merged into the payload.
     *
     * Values must be JSON-encodable scalars or arrays. Throw if the metric
     * cannot be produced accurately - a missing metric is recoverable, a
     * wrong one is not.
     *
     * @return array<string, mixed>
     */
    public function collect(): array;
}
