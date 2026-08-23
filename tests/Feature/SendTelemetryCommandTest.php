<?php

namespace Pr4w\Telemetry\Tests\Feature;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Pr4w\Telemetry\Tests\Fixtures\ExplodingCollector;
use Pr4w\Telemetry\Tests\Fixtures\OtherStubCollector;
use Pr4w\Telemetry\Tests\Fixtures\StubCollector;
use Pr4w\Telemetry\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

final class SendTelemetryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mhh-telemetry.collectors', [StubCollector::class]);
    }

    #[Test]
    public function it_sends_the_payload_and_reports_success(): void
    {
        Http::fake(['hub.test/*' => Http::response(['ok' => true])]);

        $this->artisan('mhh-telemetry:send')->assertExitCode(Command::SUCCESS);

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_exits_non_zero_when_the_hub_rejects_the_payload(): void
    {
        // The whole point of the exit code: a silent failure here means the
        // dashboard quietly goes stale for weeks.
        Http::fake(['hub.test/*' => Http::response('nope', 403)]);

        $this->artisan('mhh-telemetry:send')->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_exits_non_zero_when_a_collector_cannot_produce_its_metric(): void
    {
        Http::fake();
        config()->set('mhh-telemetry.collectors', [\stdClass::class]);

        $this->artisan('mhh-telemetry:send')->assertExitCode(Command::FAILURE);

        Http::assertNothingSent();
    }

    #[Test]
    public function an_unexpected_error_fails_cleanly_and_still_reaches_the_logs(): void
    {
        Http::fake();
        Exceptions::fake();
        config()->set('mhh-telemetry.collectors', [ExplodingCollector::class]);

        $this->artisan('mhh-telemetry:send')
            ->expectsOutputToContain('the database went away')
            ->assertExitCode(Command::FAILURE);

        // Clean console output, full stack trace in the exception handler.
        Exceptions::assertReported(RuntimeException::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_misconfiguration_is_not_reported_as_an_application_error(): void
    {
        Http::fake();
        Exceptions::fake();
        config()->set('mhh-telemetry.endpoint', null);

        $this->artisan('mhh-telemetry:send')->assertExitCode(Command::FAILURE);

        Exceptions::assertNothingReported();
    }

    #[Test]
    public function a_dry_run_collects_but_never_sends(): void
    {
        Http::fake();

        $this->artisan('mhh-telemetry:send --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_shows_money_in_units_next_to_the_raw_cents(): void
    {
        // A misplaced factor of 100 is the classic telemetry bug; printing both
        // makes it obvious on the first dry run rather than a month later.
        Http::fake();
        config()->set('mhh-telemetry.collectors', [OtherStubCollector::class]);

        $this->artisan('mhh-telemetry:send --dry-run')
            ->expectsOutputToContain('1000 (10.00)')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_does_nothing_when_telemetry_is_disabled(): void
    {
        Http::fake();
        config()->set('mhh-telemetry.enabled', false);

        $this->artisan('mhh-telemetry:send')->assertExitCode(Command::SUCCESS);

        Http::assertNothingSent();
    }
}
