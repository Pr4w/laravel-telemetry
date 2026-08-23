<?php

namespace Pr4w\Telemetry\Tests\Fixtures;

use Pr4w\Telemetry\Contracts\SubscriptionSource;

final class ArraySubscriptionSource implements SubscriptionSource
{
    /**
     * @param  list<array<string, mixed>>  $subscriptions
     */
    public function __construct(private readonly array $subscriptions = [])
    {
    }

    public function all(): iterable
    {
        yield from $this->subscriptions;
    }
}
