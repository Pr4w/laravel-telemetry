<?php

namespace Pr4w\Telemetry\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Pr4w\Telemetry\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class ScheduleRegistrationTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        return array_map(
            fn ($event) => (string) $event->command,
            $this->app->make(Schedule::class)->events(),
        );
    }

    #[Test]
    public function it_schedules_itself_so_onboarding_needs_no_kernel_changes(): void
    {
        $commands = $this->scheduledCommands();

        $this->assertTrue(
            collect($commands)->contains(fn (string $command) => str_contains($command, 'mhh-telemetry:send')),
            'mhh-telemetry:send was not registered on the schedule. Got: '.implode(', ', $commands),
        );
    }

    private function telemetryEvent(): mixed
    {
        return collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'mhh-telemetry:send'));
    }

    #[Test]
    public function it_runs_four_times_a_day_by_default(): void
    {
        $this->assertSame('0 */6 * * *', $this->telemetryEvent()->expression);
    }

    protected function hourlySchedule(Application $app): void
    {
        $app['config']->set('mhh-telemetry.schedule.cron', '30 * * * *');
    }

    #[Test]
    #[DefineEnvironment('hourlySchedule')]
    public function the_frequency_can_be_changed(): void
    {
        $this->assertSame('30 * * * *', $this->telemetryEvent()->expression);
    }

    protected function withoutSecret(Application $app): void
    {
        $this->telemetrySecret = null;
    }

    #[Test]
    #[DefineEnvironment('withoutSecret')]
    public function an_app_without_a_secret_schedules_nothing(): void
    {
        // Not set up on the hub yet: stay dormant instead of failing on the
        // scheduler four times a day.
        $this->assertFalse(
            collect($this->scheduledCommands())->contains(fn (string $command) => str_contains($command, 'mhh-telemetry:send')),
        );
    }

    protected function blankSecret(Application $app): void
    {
        $this->telemetrySecret = '   ';
    }

    #[Test]
    #[DefineEnvironment('blankSecret')]
    public function a_blank_secret_counts_as_no_secret(): void
    {
        $this->assertFalse(
            collect($this->scheduledCommands())->contains(fn (string $command) => str_contains($command, 'mhh-telemetry:send')),
        );
    }

    protected function disableSchedule(Application $app): void
    {
        $app['config']->set('mhh-telemetry.schedule.enabled', false);
    }

    #[Test]
    #[DefineEnvironment('disableSchedule')]
    public function the_schedule_can_be_turned_off(): void
    {
        $commands = $this->scheduledCommands();

        $this->assertFalse(
            collect($commands)->contains(fn (string $command) => str_contains($command, 'mhh-telemetry:send')),
        );
    }
}
