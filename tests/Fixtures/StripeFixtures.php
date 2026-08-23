<?php

namespace Pr4w\Telemetry\Tests\Fixtures;

/**
 * Minimal stand-ins for the shapes Stripe returns, trimmed to the fields the
 * calculator actually reads.
 */
final class StripeFixtures
{
    private static int $sequence = 0;

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function subscription(array $items, array $attributes = []): array
    {
        return array_merge([
            'id' => 'sub_'.++self::$sequence,
            'object' => 'subscription',
            'status' => 'active',
            'items' => [
                'object' => 'list',
                'has_more' => false,
                'data' => $items,
            ],
        ], $attributes);
    }

    /**
     * @param  list<mixed>  $discounts
     * @return array<string, mixed>
     */
    public static function item(
        ?int $unitAmount = 1000,
        string $interval = 'month',
        int $intervalCount = 1,
        int $quantity = 1,
        string $currency = 'eur',
        string $billingScheme = 'per_unit',
        string $usageType = 'licensed',
        array $discounts = [],
        ?string $unitAmountDecimal = null,
    ): array {
        return [
            'id' => 'si_'.++self::$sequence,
            'object' => 'subscription_item',
            'quantity' => $quantity,
            'discounts' => $discounts,
            'price' => [
                'id' => 'price_'.self::$sequence,
                'object' => 'price',
                'currency' => $currency,
                'unit_amount' => $unitAmount,
                'unit_amount_decimal' => $unitAmountDecimal,
                'billing_scheme' => $billingScheme,
                'recurring' => [
                    'interval' => $interval,
                    'interval_count' => $intervalCount,
                    'usage_type' => $usageType,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function discount(
        ?float $percentOff = null,
        ?int $amountOff = null,
        ?string $currency = null,
        string $duration = 'forever',
    ): array {
        return [
            'object' => 'discount',
            'id' => 'di_'.++self::$sequence,
            'coupon' => [
                'object' => 'coupon',
                'percent_off' => $percentOff,
                'amount_off' => $amountOff,
                'currency' => $currency,
                'duration' => $duration,
            ],
        ];
    }
}
