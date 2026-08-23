<?php

namespace Pr4w\Telemetry\Contracts;

interface SubscriptionSource
{
    /**
     * Yield subscriptions in Stripe's decoded shape, one at a time.
     *
     * Returning an iterable rather than an array keeps memory flat on accounts
     * with tens of thousands of subscriptions.
     *
     * @return iterable<array<string, mixed>>
     */
    public function all(): iterable;
}
