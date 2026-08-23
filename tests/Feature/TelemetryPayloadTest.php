<?php

namespace Pr4w\Telemetry\Tests\Feature;

use Illuminate\Support\Carbon;
use Pr4w\Telemetry\Exceptions\CollectorException;
use Pr4w\Telemetry\Telemetry;
use Pr4w\Telemetry\Tests\Fixtures\OtherStubCollector;
use Pr4w\Telemetry\Tests\Fixtures\StubCollector;
use Pr4w\Telemetry\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class TelemetryPayloadTest extends TestCase
{
    private function telemetry(): Telemetry
    {
        return $this->app->make(Telemetry::class);
    }

    #[Test]
    public function it_wraps_metrics_in_an_identifying_envelope(): void
    {
        Carbon::setTestNow('2026-08-23 03:00:00');
        config()->set('mhh-telemetry.collectors', [StubCollector::class]);

        $payload = $this->telemetry()->payload();

        $this->assertSame(Telemetry::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('2026-08-23T03:00:00+00:00', $payload['sent_at']);

        // The hub identifies the app by its token and records against the day
        // it received the payload, so neither a name nor a date is sent.
        $this->assertArrayNotHasKey('app', $payload);
        $this->assertArrayNotHasKey('date', $payload);
    }

    #[Test]
    public function it_flattens_metrics_onto_the_payload_for_backwards_compatibility(): void
    {
        config()->set('mhh-telemetry.collectors', [StubCollector::class, OtherStubCollector::class]);

        $payload = $this->telemetry()->payload();

        // The hub's existing endpoint reads these two keys at the top level.
        $this->assertSame(42, $payload['total_users']);
        $this->assertSame(1000, $payload['mrr_cents']);
    }

    #[Test]
    public function it_refuses_to_let_two_collectors_fight_over_a_metric_name(): void
    {
        OtherStubCollector::$metrics = ['total_users' => 7];
        config()->set('mhh-telemetry.collectors', [StubCollector::class, OtherStubCollector::class]);

        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('total_users');

        $this->telemetry()->payload();

        OtherStubCollector::$metrics = ['mrr_cents' => 1000];
    }

    #[Test]
    public function it_rejects_a_configured_class_that_is_not_a_collector(): void
    {
        config()->set('mhh-telemetry.collectors', [\stdClass::class]);

        $this->expectException(CollectorException::class);

        $this->telemetry()->payload();
    }

    #[Test]
    public function it_can_be_disabled_per_environment(): void
    {
        config()->set('mhh-telemetry.enabled', false);

        $this->assertFalse($this->telemetry()->enabled());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        OtherStubCollector::$metrics = ['mrr_cents' => 1000];
        StubCollector::$metrics = ['total_users' => 42];

        parent::tearDown();
    }
}
