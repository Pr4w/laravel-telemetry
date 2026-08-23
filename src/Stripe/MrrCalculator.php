<?php

namespace Pr4w\Telemetry\Stripe;

/**
 * Normalises Stripe subscriptions into monthly recurring revenue, in cents,
 * grouped by currency.
 *
 * Pure: it takes decoded Stripe payloads (arrays, as returned by
 * StripeObject::toArray()) and returns a result. No I/O, no framework, so the
 * arithmetic can be tested exhaustively against fixtures.
 *
 * What it deliberately does NOT guess at:
 *  - tiered / graduated prices, where the amount depends on usage
 *  - metered prices, where quantity is only known at invoice time
 *  - prices with no unit amount at all
 * Each of those is recorded as an issue rather than silently counted as zero.
 */
final class MrrCalculator
{
    /** Intervals Stripe supports on a recurring price, as a fraction of a month. */
    private const INTERVALS = ['day', 'week', 'month', 'year'];

    /**
     * @param  iterable<array<string, mixed>>  $subscriptions
     */
    public function calculate(iterable $subscriptions): MrrResult
    {
        /** @var array<string, float> $totals */
        $totals = [];
        /** @var list<array{subscription: string, item: string|null, reason: string}> $issues */
        $issues = [];
        $count = 0;

        foreach ($subscriptions as $subscription) {
            $count++;

            /** @var array<string, float> $subtotals */
            $subtotals = [];

            foreach ($subscription['items']['data'] ?? [] as $item) {
                $contribution = $this->monthlyAmountForItem($subscription, $item, $issues);

                if ($contribution === null) {
                    continue;
                }

                [$currency, $amount] = $contribution;
                $subtotals[$currency] = ($subtotals[$currency] ?? 0.0) + $amount;
            }

            $subtotals = $this->applySubscriptionDiscounts($subscription, $subtotals, $issues);

            foreach ($subtotals as $currency => $amount) {
                $totals[$currency] = ($totals[$currency] ?? 0.0) + $amount;
            }
        }

        // Round once, at the very end, so long chains of monthly conversions
        // don't accumulate rounding error per subscription.
        $rounded = array_map(static fn (float $amount): int => (int) round($amount), $totals);
        ksort($rounded);

        return new MrrResult($rounded, $issues, $count);
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>  $item
     * @param  list<array{subscription: string, item: string|null, reason: string}>  $issues
     * @return array{0: string, 1: float}|null  [currency, monthly cents]
     */
    private function monthlyAmountForItem(array $subscription, array $item, array &$issues): ?array
    {
        $subscriptionId = (string) ($subscription['id'] ?? 'unknown');
        $itemId = isset($item['id']) ? (string) $item['id'] : null;

        $note = function (string $reason) use (&$issues, $subscriptionId, $itemId): null {
            $issues[] = ['subscription' => $subscriptionId, 'item' => $itemId, 'reason' => $reason];

            return null;
        };

        // `plan` is the legacy shape; older API versions omit `price`.
        $price = $item['price'] ?? $item['plan'] ?? null;

        if (! is_array($price)) {
            return $note('subscription item has no expanded price object');
        }

        $recurring = $price['recurring'] ?? null;

        if (! is_array($recurring) || ! isset($recurring['interval'])) {
            return $note('price is not recurring');
        }

        $interval = (string) $recurring['interval'];

        if (! in_array($interval, self::INTERVALS, true)) {
            return $note("unrecognised billing interval [{$interval}]");
        }

        if (($price['billing_scheme'] ?? 'per_unit') !== 'per_unit') {
            return $note('tiered pricing - monthly value depends on usage');
        }

        if (($recurring['usage_type'] ?? 'licensed') === 'metered') {
            return $note('metered usage - quantity is only known at invoice time');
        }

        $unitAmount = $this->unitAmount($price);

        if ($unitAmount === null) {
            return $note('price has no unit amount');
        }

        $currency = strtolower((string) ($price['currency'] ?? ''));

        if ($currency === '') {
            return $note('price has no currency');
        }

        // Per-invoice amount for this line, before any discount.
        $amount = $unitAmount * (float) ($item['quantity'] ?? 1);

        // Item-level discounts reduce the invoice line, so they apply before
        // the interval is normalised to a month.
        $amount = $this->discounted(
            $amount,
            $this->discountsOf($item, $issues, $subscriptionId, $itemId),
            $currency,
            $recurring,
        );

        return [$currency, $this->toMonthly($amount, $recurring)];
    }

    /**
     * Subscription-level discounts apply to the whole invoice. The subtotals
     * handed in are already monthly, so a fixed `amount_off` has to be
     * normalised against the subscription's own billing interval.
     *
     * @param  array<string, mixed>  $subscription
     * @param  array<string, float>  $subtotals
     * @param  list<array{subscription: string, item: string|null, reason: string}>  $issues
     * @return array<string, float>
     */
    private function applySubscriptionDiscounts(array $subscription, array $subtotals, array &$issues): array
    {
        $subscriptionId = (string) ($subscription['id'] ?? 'unknown');
        $discounts = $this->discountsOf($subscription, $issues, $subscriptionId, null);

        if ($discounts === [] || $subtotals === []) {
            return $subtotals;
        }

        $billingInterval = $subscription['items']['data'][0]['price']['recurring']
            ?? ['interval' => 'month', 'interval_count' => 1];

        foreach ($subtotals as $currency => $amount) {
            $subtotals[$currency] = $this->discounted($amount, $discounts, $currency, $billingInterval, true);
        }

        return $subtotals;
    }

    /**
     * @param  list<array<string, mixed>>  $discounts
     * @param  array<string, mixed>  $recurring
     */
    private function discounted(
        float $amount,
        array $discounts,
        string $currency,
        array $recurring,
        bool $amountIsMonthly = false,
    ): float {
        foreach ($discounts as $discount) {
            if (! $this->discountIsRecurring($discount)) {
                continue;
            }

            $coupon = $discount['coupon'] ?? null;

            if (! is_array($coupon)) {
                continue;
            }

            if (isset($coupon['percent_off'])) {
                $amount *= 1 - ((float) $coupon['percent_off'] / 100);

                continue;
            }

            if (isset($coupon['amount_off'])) {
                // A coupon in another currency cannot apply to this line.
                if (strtolower((string) ($coupon['currency'] ?? $currency)) !== $currency) {
                    continue;
                }

                $off = (float) $coupon['amount_off'];

                $amount -= $amountIsMonthly ? $this->toMonthly($off, $recurring) : $off;
            }
        }

        return max(0.0, $amount);
    }

    /**
     * A `once` coupon covers a single invoice and is not part of the recurring
     * run rate. `forever` and `repeating` are.
     *
     * @param  array<string, mixed>  $discount
     */
    private function discountIsRecurring(array $discount): bool
    {
        $duration = $discount['coupon']['duration'] ?? 'forever';

        return $duration !== 'once';
    }

    /**
     * Stripe returns discounts as IDs unless they were expanded. Counting an
     * unexpanded discount as "no discount" would overstate MRR, so say so.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array{subscription: string, item: string|null, reason: string}>  $issues
     * @return list<array<string, mixed>>
     */
    private function discountsOf(array $object, array &$issues, string $subscriptionId, ?string $itemId): array
    {
        $raw = $object['discounts'] ?? null;

        if (! is_array($raw) || $raw === []) {
            $single = $object['discount'] ?? null;

            return is_array($single) ? [$single] : [];
        }

        $expanded = [];

        foreach ($raw as $discount) {
            if (is_array($discount)) {
                $expanded[] = $discount;

                continue;
            }

            $issues[] = [
                'subscription' => $subscriptionId,
                'item' => $itemId,
                'reason' => 'discount was not expanded, so it could not be applied',
            ];
        }

        return $expanded;
    }

    /**
     * `unit_amount_decimal` is authoritative: it carries sub-cent precision
     * that `unit_amount` rounds away.
     *
     * @param  array<string, mixed>  $price
     */
    private function unitAmount(array $price): ?float
    {
        if (($price['unit_amount_decimal'] ?? null) !== null) {
            return (float) $price['unit_amount_decimal'];
        }

        if (($price['unit_amount'] ?? null) !== null) {
            return (float) $price['unit_amount'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $recurring
     */
    private function toMonthly(float $amount, array $recurring): float
    {
        $count = max(1, (int) ($recurring['interval_count'] ?? 1));

        return match ($recurring['interval'] ?? 'month') {
            'day' => $amount * 365 / 12 / $count,
            'week' => $amount * 52 / 12 / $count,
            'year' => $amount / 12 / $count,
            default => $amount / $count,
        };
    }
}
