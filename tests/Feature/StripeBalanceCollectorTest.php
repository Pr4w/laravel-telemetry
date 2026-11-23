<?php

namespace Pr4w\Telemetry\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Pr4w\Telemetry\Collectors\StripeBalanceCollector;
use Pr4w\Telemetry\Contracts\BalanceSource;
use Pr4w\Telemetry\Tests\Fixtures\ArrayBalanceSource;
use Pr4w\Telemetry\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class StripeBalanceCollectorTest extends TestCase
{
    /**
     * @param  list<array{amount: int, currency: string}>  $available
     * @param  list<array{amount: int, currency: string}>  $pending
     */
    private function collect(array $available, array $pending = []): array
    {
        $this->app->instance(BalanceSource::class, new ArrayBalanceSource([
            'object' => 'balance',
            'available' => $available,
            'pending' => $pending,
        ]));

        return $this->app->make(StripeBalanceCollector::class)->collect();
    }

    /**
     * @return array{amount: int, currency: string}
     */
    private static function money(int $amount, string $currency = 'eur'): array
    {
        return ['amount' => $amount, 'currency' => $currency];
    }

    #[Test]
    public function it_adds_available_and_pending(): void
    {
        $metrics = $this->collect([self::money(10000)], [self::money(2500)]);

        $this->assertSame(['stripe_balance_cents' => 12500], $metrics);
    }

    #[Test]
    public function it_reports_zero_for_an_empty_balance(): void
    {
        $this->assertSame(['stripe_balance_cents' => 0], $this->collect([], []));
    }

    #[Test]
    public function it_keeps_a_negative_balance_as_stripe_reports_it(): void
    {
        // Refunds and disputes can exceed incoming payments; that is a real
        // state of the account, not something to clamp to zero.
        $metrics = $this->collect([self::money(-3000)], [self::money(1000)]);

        $this->assertSame(['stripe_balance_cents' => -2000], $metrics);
    }

    #[Test]
    public function it_leaves_other_currencies_out_and_says_so(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'USD 50.00')
        );

        $metrics = $this->collect([self::money(10000), self::money(5000, 'usd')]);

        $this->assertSame(['stripe_balance_cents' => 10000], $metrics);
    }

    #[Test]
    public function it_does_not_warn_about_an_empty_balance_in_another_currency(): void
    {
        Log::shouldReceive('warning')->never();

        $metrics = $this->collect([self::money(10000), self::money(0, 'usd')]);

        $this->assertSame(['stripe_balance_cents' => 10000], $metrics);
    }

    #[Test]
    public function it_reports_in_the_same_currency_as_mrr(): void
    {
        config()->set('mhh-telemetry.stripe.currency', 'USD');
        Log::shouldReceive('warning')->once();

        $metrics = $this->collect([self::money(10000), self::money(7000, 'usd')]);

        $this->assertSame(['stripe_balance_cents' => 7000], $metrics);
    }
}
