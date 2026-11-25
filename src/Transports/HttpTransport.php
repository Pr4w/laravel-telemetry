<?php

namespace Pr4w\Telemetry\Transports;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Pr4w\Telemetry\Contracts\Transport;
use Pr4w\Telemetry\Exceptions\TransportException;
use Throwable;

class HttpTransport implements Transport
{
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    private const RETRY_TIMES = 3;

    private const RETRY_SLEEP_MS = 500;

    public function __construct(
        private readonly Repository $config,
        private readonly HttpFactory $http,
    ) {
    }

    public function send(array $payload): void
    {
        $endpoint = $this->required('endpoint');
        $secret = $this->required('secret');

        try {
            $response = $this->http
                ->withHeaders([
                    'X-App-Token' => $secret,
                    'Accept' => 'application/json',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->retry(
                    self::RETRY_TIMES,
                    $this->retrySleep(),
                    when: $this->shouldRetry(...),
                    throw: false,
                )
                ->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            throw TransportException::connectionFailed($e->getMessage());
        }

        if ($response->failed()) {
            throw TransportException::requestFailed($response->status(), $response->body());
        }
    }

    /**
     * Retry only what a retry can fix. A 401 means the token is wrong and a 422
     * means the payload is wrong; hammering the hub two more times just turns
     * one misconfiguration into three failed requests a night.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            // Timeouts, DNS failures, refused connections - all worth another go.
            return true;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    private function retrySleep(): int
    {
        // Tests set this to 0 so the suite doesn't actually wait.
        return (int) $this->config->get('mhh-telemetry.retry_sleep', self::RETRY_SLEEP_MS);
    }

    private function required(string $key): string
    {
        $value = $this->config->get("mhh-telemetry.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw TransportException::missingConfiguration("mhh-telemetry.{$key}", 'TELEMETRY_'.strtoupper($key));
        }

        return trim($value);
    }
}
