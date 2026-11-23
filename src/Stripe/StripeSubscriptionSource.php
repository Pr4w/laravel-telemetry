<?php

namespace Pr4w\Telemetry\Stripe;

use Generator;
use Illuminate\Contracts\Config\Repository;
use Pr4w\Telemetry\Contracts\SubscriptionSource;
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
     * discount overstates MRR.
     */
    private const DEFAULT_EXPAND = [
        'data.discounts',
        'data.items.data.discounts',
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly StripeClientFactory $clients,
    ) {
    }

    /**
     * @return Generator<array<string, mixed>>
     */
    public function all(): Generator
    {
        $client = $this->clients->make();
        $statuses = (array) $this->config->get('mhh-telemetry.stripe.statuses', ['active']);

        foreach ($statuses as $status) {
            $page = $client->subscriptions->all([
                'status' => $status,
                'limit' => 100,
                'expand' => self::DEFAULT_EXPAND,
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
}
