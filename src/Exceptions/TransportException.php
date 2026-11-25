<?php

namespace Pr4w\Telemetry\Exceptions;

class TransportException extends TelemetryException
{
    public static function missingConfiguration(string $key, string $env): self
    {
        return new self("Telemetry is not configured: [{$key}] is empty. Set {$env} in .env.");
    }

    public static function requestFailed(int $status, string $body): self
    {
        return new self("Hub rejected the payload with HTTP {$status}: ".mb_strimwidth($body, 0, 500, '...'));
    }

    public static function connectionFailed(string $message): self
    {
        return new self("Could not reach the telemetry hub: {$message}");
    }
}
