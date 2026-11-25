<?php

namespace Pr4w\Telemetry\Tests;

use Pr4w\Telemetry\TelemetryServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The hub secret the app boots with; null boots an app that has not been
     * set up on the hub yet. Change it from a #[DefineEnvironment] method:
     * Testbench runs those before defineEnvironment(), so setting the config
     * key there directly would be overwritten.
     */
    protected ?string $telemetrySecret = 'shared-secret';

    protected function getPackageProviders($app): array
    {
        return [TelemetryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('mhh-telemetry.endpoint', 'https://hub.test/telemetry');
        $config->set('mhh-telemetry.secret', $this->telemetrySecret);
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
