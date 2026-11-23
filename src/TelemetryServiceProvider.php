<?php

namespace Pr4w\Telemetry;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Pr4w\Telemetry\Commands\SendTelemetryCommand;
use Pr4w\Telemetry\Contracts\BalanceSource;
use Pr4w\Telemetry\Contracts\SubscriptionSource;
use Pr4w\Telemetry\Contracts\Transport;
use Pr4w\Telemetry\Stripe\StripeBalanceSource;
use Pr4w\Telemetry\Stripe\StripeClientFactory;
use Pr4w\Telemetry\Stripe\StripeSubscriptionSource;
use Pr4w\Telemetry\Transports\HttpTransport;

class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mhh-telemetry.php', 'mhh-telemetry');

        $this->app->bind(SubscriptionSource::class, fn (Application $app) => new StripeSubscriptionSource(
            $app['config'],
            $app->make(StripeClientFactory::class),
        ));

        $this->app->bind(BalanceSource::class, fn (Application $app) => new StripeBalanceSource(
            $app->make(StripeClientFactory::class),
        ));

        $this->app->singleton(Transport::class, fn (Application $app) => new HttpTransport(
            $app['config'],
            $app->make(HttpFactory::class),
        ));

        $this->app->singleton(Telemetry::class, fn (Application $app) => new Telemetry(
            $app['config'],
            $app,
            $app->make(Transport::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mhh-telemetry.php' => $this->app->configPath('mhh-telemetry.php'),
            ], 'mhh-telemetry-config');

            $this->commands([SendTelemetryCommand::class]);
        }

        $this->registerSchedule();
    }

    /**
     * Registering the schedule here keeps onboarding to `composer require` plus
     * env vars, with nothing to remember in routes/console.php.
     */
    private function registerSchedule(): void
    {
        if (! $this->app['config']->get('mhh-telemetry.schedule.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule, Application $app): void {
            // No onOneServer(): it needs a lock-capable cache store, and the
            // hub upserts on the time slot, so a second server sending the
            // same numbers is a no-op rather than a duplicate row.
            $schedule->command(SendTelemetryCommand::class)
                ->cron((string) $app['config']->get('mhh-telemetry.schedule.cron', '0 */6 * * *'))
                ->withoutOverlapping();
        });
    }
}
