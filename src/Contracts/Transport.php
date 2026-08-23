<?php

namespace Pr4w\Telemetry\Contracts;

use Pr4w\Telemetry\Exceptions\TransportException;

interface Transport
{
    /**
     * Deliver the payload to the hub.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws TransportException
     */
    public function send(array $payload): void;
}
