<?php

namespace Pr4w\Telemetry\Tests;

use Pr4w\Telemetry\TelemetryServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TelemetryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('mhh-telemetry.endpoint', 'https://hub.test/telemetry');
        $config->set('mhh-telemetry.secret', 'shared-secret');
        $config->set('mhh-telemetry.collectors', []);
        $config->set('mhh-telemetry.retry_sleep', 0);

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
