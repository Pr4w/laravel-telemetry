<?php

namespace Pr4w\Telemetry\Tests\Unit;

use Pr4w\Telemetry\Stripe\MrrCalculator;
use Pr4w\Telemetry\Stripe\MrrResult;
use Pr4w\Telemetry\Tests\Fixtures\StripeFixtures as Stripe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MrrCalculatorTest extends TestCase
{
    private function calculate(array $subscriptions): MrrResult
    {
        return (new MrrCalculator)->calculate($subscriptions);
    }

    // ---------------------------------------------------------------- intervals

    /**
     * @return iterable<string, array{0: string, 1: int, 2: int, 3: int}>
     */
    public static function intervals(): iterable
    {
        //                              interval, count, unit amount, expected monthly cents
        yield 'monthly' => ['month', 1, 1000, 1000];
        yield 'quarterly' => ['month', 3, 3000, 1000];
        yield 'yearly' => ['year', 1, 12000, 1000];
        yield 'biennial' => ['year', 2, 24000, 1000];
        yield 'weekly' => ['week', 1, 1000, 4333];   // 1000 * 52 / 12
        yield 'daily' => ['day', 1, 100, 3042];      // 100 * 365 / 12
    }

    #[Test]
    #[DataProvider('intervals')]
    public function it_normalises_every_billing_interval_to_a_month(
        string $interval,
        int $intervalCount,
        int $unitAmount,
        int $expected,
    ): void {
        $result = $this->calculate([
            Stripe::subscription([
                Stripe::item(unitAmount: $unitAmount, interval: $interval, intervalCount: $intervalCount),
            ]),
        ]);

        $this->assertSame(['eur' => $expected], $result->totals);
        $this->assertTrue($result->isComplete());
    }

    #[Test]
    public function it_multiplies_by_quantity(): void
    {
        $result = $this->calculate([
            Stripe::subscription([Stripe::item(unitAmount: 1000, quantity: 3)]),
        ]);

        $this->assertSame(3000, $result->total('eur'));
    }

    #[Test]
    public function it_sums_line_items_and_subscriptions(): void
    {
        $result = $this->calculate([
            Stripe::subscription([
                Stripe::item(unitAmount: 1000),
                Stripe::item(unitAmount: 12000, interval: 'year'),
            ]),
            Stripe::subscription([Stripe::item(unitAmount: 500)]),
        ]);

        $this->assertSame(2500, $result->total('eur'));
        $this->assertSame(2, $result->subscriptionCount);
    }

    #[Test]
    public function it_rounds_once_at_the_end_rather_than_per_subscription(): void
    {
        // 1000 every 3 months is 333.33/month. Rounding per subscription would
        // give 333 + 333 = 666; rounding once gives 667.
        $result = $this->calculate([
            Stripe::subscription([Stripe::item(unitAmount: 1000, interval: 'month', intervalCount: 3)]),
            Stripe::subscription([Stripe::item(unitAmount: 1000, interval: 'month', intervalCount: 3)]),
        ]);

        $this->assertSame(667, $result->total('eur'));
    }

    #[Test]
    public function it_prefers_the_decimal_unit_amount_when_present(): void
    {
        $result = $this->calculate([
            Stripe::subscription([
                Stripe::item(unitAmount: 1000, quantity: 3, unitAmountDecimal: '1000.5'),
            ]),
        ]);

        $this->assertSame(3002, $result->total('eur')); // 1000.5 * 3 = 3001.5 -> 3002
    }

    #[Test]
    public function it_returns_nothing_for_no_subscriptions(): void
    {
        $result = $this->calculate([]);

        $this->assertSame([], $result->totals);
        $this->assertSame(0, $result->subscriptionCount);
        $this->assertTrue($result->isComplete());
    }

    // ------------------------------------------------------- indeterminate value

    #[Test]
    public function it_flags_tiered_prices_instead_of_counting_them_as_zero(): void
    {
        $result = $this->calculate([
            Stripe::subscription([Stripe::item(unitAmount: null, billingScheme: 'tiered')]),
            Stripe::subscription([Stripe::item(unitAmount: 1000)]),
        ]);

        $this->assertSame(1000, $result->total('eur'));
        $this->assertFalse($result->isComplete());
        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('tiered', $result->issues[0]['reason']);
    }

    #[Test]
    public function it_flags_metered_prices(): void
    {
        $result = $this->calculate([
            Stripe::subscription([Stripe::item(usageType: 'metered')]),
        ]);

        $this->assertSame([], $result->totals);
        $this->assertStringContainsString('metered', $result->issues[0]['reason']);
    }

    #[Test]
    public function it_flags_a_price_with_no_unit_amount(): void
    {
        $result = $this->calculate([
            Stripe::subscription([Stripe::item(unitAmount: null)]),
        ]);

        $this->assertSame([], $result->totals);
        $this->assertStringContainsString('no unit amount', $result->issues[0]['reason']);
    }

    #[Test]
    public function it_flags_discounts_that_were_not_expanded(): void
    {
        $result = $this->calculate([
            Stripe::subscription(
                [Stripe::item(unitAmount: 1000)],
                ['discounts' => ['di_unexpanded']],
            ),
        ]);

        $this->assertSame(1000, $result->total('eur'));
        $this->assertStringContainsString('not expanded', $result->issues[0]['reason']);
    }

    #[Test]
    public function it_records_the_subscription_and_item_on_every_issue(): void
    {
        $subscription = Stripe::subscription([Stripe::item(unitAmount: null)]);

        $result = $this->calculate([$subscription]);

        $this->assertSame($subscription['id'], $result->issues[0]['subscription']);
        $this->assertSame($subscription['items']['data'][0]['id'], $result->issues[0]['item']);
    }

    // ---------------------------------------------------------------- discounts

    #[Test]
    public function it_applies_a_percentage_discount(): void
    {
        $result = $this->calculate([
            Stripe::subscription(
                [Stripe::item(unitAmount: 1000)],
                ['discounts' => [Stripe::discount(percentOff: 50)]],
            ),
        ]);

        $this->assertSame(500, $result->total('eur'));
    }

    #[Test]
    public function it_normalises_a_fixed_discount_against_the_billing_interval(): void
    {
        // 12000/year is 1000/month; a 1200/year coupon is 100/month off.
        $result = $this->calculate([
            Stripe::subscription(
                [Stripe::item(unitAmount: 12000, interval: 'year')],
                ['discounts' => [Stripe::discount(amountOff: 1200, currency: 'eur')]],
            ),
        ]);

        $this->assertSame(900, $result->total('eur'));
    }

    #[Test]
    public function it_ignores_one_off_coupons_which_are_not_part_of_the_run_rate(): void
    {
        $result = $this->calculate([
            Stripe::subscription(
                [Stripe::item(unitAmount: 1000)],
                ['discounts' => [Stripe::discount(percentOff: 100, duration: 'once')]],
            ),
        ]);

        $this->assertSame(1000, $result->total('eur'));
    }

    #[Test]
    public function it_applies_repeating_coupons(): void
    {
        $result = $this->calculate([
            Stripe::subscription(
                [Stripe::item(unitAmount: 1000)],
                ['discounts' => [Stripe::discount(percentOff: 25, duration: 'repeating')]],
            ),
        ]);

        $this->assertSame(750, $result->total('eur'));
    }

    #[Test]
    public function it_applies_item_level_discounts(): void
    {
        $result = $this->calculate([
            Stripe::subscription([
                Stripe::item(unitAmount: 1000, discounts: [Stripe::discount(percentOff: 10)]),
                Stripe::item(unitAmount: 1000),
            ]),
        ]);

        $this->assertSame(1900, $result->total('eur'));
    }

    #[Test]
    public function it_ignores_a_fixed_discount_denominated_in_another_currency(): void
    {
        $result = $this->calculate([
            Stripe::subscription(
                [Stripe::item(unitAmount: 1000, currency: 'eur')],
                ['discounts' => [Stripe::discount(amountOff: 500, currency: 'usd')]],
            ),
        ]);

        $this->assertSame(1000, $result->total('eur'));
    }

    #[Test]
    public function it_never_lets_a_discount_push_a_line_below_zero(): void
    {
        $result = $this->calculate([
            Stripe::subscription(
                [Stripe::item(unitAmount: 1000)],
                ['discounts' => [Stripe::discount(amountOff: 5000, currency: 'eur')]],
            ),
        ]);

        $this->assertSame(0, $result->total('eur'));
    }

    #[Test]
    public function it_reads_the_legacy_singular_discount_field(): void
    {
        $result = $this->calculate([
            Stripe::subscription(
                [Stripe::item(unitAmount: 1000)],
                ['discount' => Stripe::discount(percentOff: 50)],
            ),
        ]);

        $this->assertSame(500, $result->total('eur'));
    }

    // --------------------------------------------------------------- currencies

    #[Test]
    public function it_keeps_currencies_apart_rather_than_summing_cents(): void
    {
        $result = $this->calculate([
            Stripe::subscription([Stripe::item(unitAmount: 1000, currency: 'eur')]),
            Stripe::subscription([Stripe::item(unitAmount: 2000, currency: 'usd')]),
            Stripe::subscription([Stripe::item(unitAmount: 500, currency: 'EUR')]),
        ]);

        $this->assertSame(['eur' => 1500, 'usd' => 2000], $result->totals);
        $this->assertSame(['eur', 'usd'], $result->currencies());
    }

    // -------------------------------------------------------------- legacy shape

    #[Test]
    public function it_falls_back_to_the_legacy_plan_object(): void
    {
        $item = Stripe::item(unitAmount: 1000);
        $item['plan'] = $item['price'];
        unset($item['price']);

        $result = $this->calculate([Stripe::subscription([$item])]);

        $this->assertSame(1000, $result->total('eur'));
    }
}
