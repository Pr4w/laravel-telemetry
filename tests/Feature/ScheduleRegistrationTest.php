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

    #[Test]
    public function it_runs_daily_at_the_configured_time(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'mhh-telemetry:send'));

        $this->assertSame('0 3 * * *', $event->expression);
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
