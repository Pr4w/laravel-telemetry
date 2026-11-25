<?php

namespace Pr4w\Telemetry\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pr4w\Telemetry\Contracts\Transport;
use Pr4w\Telemetry\Exceptions\TransportException;
use Pr4w\Telemetry\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HttpTransportTest extends TestCase
{
    private const PAYLOAD = [
        'schema_version' => 1,
        'date' => '2026-08-23',
        'total_users' => 42,
        'mrr_cents' => 1000,
    ];

    private function transport(): Transport
    {
        return $this->app->make(Transport::class);
    }

    #[Test]
    public function it_posts_the_payload_to_the_configured_endpoint(): void
    {
        Http::fake(['hub.test/*' => Http::response(['ok' => true])]);

        $this->transport()->send(self::PAYLOAD);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://hub.test/telemetry'
                && $request->method() === 'POST'
                && $request['total_users'] === 42
                && $request['mrr_cents'] === 1000
                && $request->header('X-App-Token')[0] === 'shared-secret';
        });
    }

    #[Test]
    public function it_retries_before_giving_up_on_a_failing_hub(): void
    {
        Http::fake(['hub.test/*' => Http::response('upstream exploded', 500)]);

        try {
            $this->transport()->send(self::PAYLOAD);
            $this->fail('Expected a TransportException.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertStringContainsString('upstream exploded', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    #[Test]
    public function it_does_not_retry_a_rejected_payload(): void
    {
        Http::fake(['hub.test/*' => Http::response(['error' => 'bad token'], 401)]);

        $this->expectException(TransportException::class);

        try {
            $this->transport()->send(self::PAYLOAD);
        } finally {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function it_fails_loudly_when_the_endpoint_is_not_configured(): void
    {
        config()->set('mhh-telemetry.endpoint', null);
        Http::fake();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('mhh-telemetry.endpoint');

        $this->transport()->send(self::PAYLOAD);
    }

    #[Test]
    public function it_fails_loudly_when_the_secret_is_not_configured(): void
    {
        config()->set('mhh-telemetry.secret', '   ');
        Http::fake();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Set TELEMETRY_SECRET in .env');

        $this->transport()->send(self::PAYLOAD);
    }
}
