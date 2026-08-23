<?php

namespace Pr4w\Telemetry;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Pr4w\Telemetry\Contracts\Collector;
use Pr4w\Telemetry\Contracts\Transport;
use Pr4w\Telemetry\Exceptions\CollectorException;

/**
 * Runs the configured collectors and hands the resulting payload to the
 * transport.
 */
class Telemetry
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly Repository $config,
        private readonly Container $container,
        private readonly Transport $transport,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('mhh-telemetry.enabled', true);
    }

    /**
     * Build the full payload without sending it.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        // Envelope fields first, then metrics flattened on top so the wire
        // format stays a flat object - the hub reads `total_users` and
        // `mrr_cents` off the top level. The app is identified by its token,
        // so there is deliberately no name in the body.
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sent_at' => Carbon::now()->toIso8601String(),
            ...$this->collect(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $metrics = [];

        foreach ((array) $this->config->get('mhh-telemetry.collectors', []) as $class) {
            $collector = is_string($class) ? $this->container->make($class) : $class;

            if (! $collector instanceof Collector) {
                throw CollectorException::notACollector(is_string($class) ? $class : get_debug_type($class));
            }

            foreach ($collector->collect() as $metric => $value) {
                if (array_key_exists($metric, $metrics)) {
                    throw CollectorException::duplicateMetric($metric, $collector::class);
                }

                $metrics[$metric] = $value;
            }
        }

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        $this->transport->send($payload);
    }

}
