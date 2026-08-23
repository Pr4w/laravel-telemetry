<?php

namespace Pr4w\Telemetry\Stripe;

use Generator;
use Illuminate\Contracts\Config\Repository;
use Pr4w\Telemetry\Contracts\SubscriptionSource;
use Pr4w\Telemetry\Exceptions\CollectorException;
use Stripe\StripeClient;

/**
 * Reads subscriptions from the Stripe API.
 *
 * Uses stripe-php rather than raw HTTP so the API version is pinned by the
 * library, pagination is handled, and the response is typed.
 */
class StripeSubscriptionSource implements SubscriptionSource
{
    /**
     * Discounts come back as bare IDs unless expanded, and an unapplied
     * discount overstates MRR. Defaulted here as well as in the config file:
     * mergeConfigFrom is shallow, so an app that published its config before
     * this key existed would otherwise lose it.
     */
    /**
     * Pinned so upgrading stripe-php can't quietly change the response shape
     * underneath the MRR calculation. Matches what stripe-php 17 ships with.
     */
    private const DEFAULT_API_VERSION = '2025-08-27.basil';

    private const DEFAULT_EXPAND = [
        'data.discounts',
        'data.items.data.discounts',
    ];

    public function __construct(
        private readonly Repository $config,
    ) {
    }

    /**
     * @return Generator<array<string, mixed>>
     */
    public function all(): Generator
    {
        $client = $this->client();
        $statuses = (array) $this->config->get('mhh-telemetry.stripe.statuses', ['active']);
        $expand = self::DEFAULT_EXPAND;

        foreach ($statuses as $status) {
            $page = $client->subscriptions->all([
                'status' => $status,
                'limit' => 100,
                'expand' => $expand,
            ]);

            foreach ($page->autoPagingIterator() as $subscription) {
                yield $this->withAllItems($client, $subscription->toArray());
            }
        }
    }

    /**
     * A subscription's `items` list is itself paginated and capped at 10.
     * Without this, an 11-item subscription would silently under-report.
     *
     * @param  array<string, mixed>  $subscription
     * @return array<string, mixed>
     */
    private function withAllItems(StripeClient $client, array $subscription): array
    {
        if (($subscription['items']['has_more'] ?? false) !== true) {
            return $subscription;
        }

        $items = [];

        $page = $client->subscriptionItems->all([
            'subscription' => $subscription['id'],
            'limit' => 100,
        ]);

        foreach ($page->autoPagingIterator() as $item) {
            $items[] = $item->toArray();
        }

        $subscription['items']['data'] = $items;
        $subscription['items']['has_more'] = false;

        return $subscription;
    }

    private function client(): StripeClient
    {
        if (! class_exists(StripeClient::class)) {
            throw new CollectorException(
                'The Stripe MRR collector needs stripe/stripe-php. Install it, or remove the collector '
                .'from [mhh-telemetry.collectors].'
            );
        }

        // Same places the original script looked: Cashier first, then the
        // plain STRIPE_SECRET every app already has. All read through config,
        // because env() returns null once the app runs config:cache.
        $secret = $this->config->get('cashier.secret')
            ?: $this->config->get('services.stripe.secret')
            ?: $this->config->get('mhh-telemetry.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new CollectorException(
                'No Stripe secret key found. Expected cashier.secret, services.stripe.secret, or STRIPE_SECRET.'
            );
        }

        return new StripeClient([
            'api_key' => $secret,
            'stripe_version' => $this->config->get('mhh-telemetry.stripe.api_version', self::DEFAULT_API_VERSION),
        ]);
    }
}
