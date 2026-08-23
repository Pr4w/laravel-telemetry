<?php

namespace Pr4w\Telemetry\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Pr4w\Telemetry\Collectors\StripeMrrCollector;
use Pr4w\Telemetry\Contracts\SubscriptionSource;
use Pr4w\Telemetry\Exceptions\CollectorException;
use Pr4w\Telemetry\Tests\Fixtures\ArraySubscriptionSource;
use Pr4w\Telemetry\Tests\Fixtures\StripeFixtures as Stripe;
use Pr4w\Telemetry\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class StripeMrrCollectorTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $subscriptions
     */
    private function collect(array $subscriptions): array
    {
        $this->app->instance(SubscriptionSource::class, new ArraySubscriptionSource($subscriptions));

        return $this->app->make(StripeMrrCollector::class)->collect();
    }

    #[Test]
    public function it_reports_mrr_with_its_currency_and_a_full_breakdown(): void
    {
        $metrics = $this->collect([
            Stripe::subscription([Stripe::item(unitAmount: 1000)]),
            Stripe::subscription([Stripe::item(unitAmount: 12000, interval: 'year')]),
        ]);

        $this->assertSame(2000, $metrics['mrr_cents']);
        $this->assertSame('eur', $metrics['currency']);
        $this->assertSame(['eur' => 2000], $metrics['mrr_by_currency']);
        $this->assertSame(2, $metrics['active_subscriptions']);
    }

    #[Test]
    public function it_reports_zero_for_an_account_with_no_subscriptions(): void
    {
        $metrics = $this->collect([]);

        $this->assertSame(0, $metrics['mrr_cents']);
        $this->assertSame(0, $metrics['active_subscriptions']);
    }

    // ------------------------------------------------------------ value policy

    #[Test]
    public function it_fails_by_default_rather_than_report_an_understated_figure(): void
    {
        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('MRR is incomplete');

        $this->collect([
            Stripe::subscription([Stripe::item(unitAmount: null, billingScheme: 'tiered')]),
        ]);
    }

    #[Test]
    public function it_can_be_told_to_warn_and_report_anyway(): void
    {
        config()->set('mhh-telemetry.stripe.on_indeterminate_price', 'warn');
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'MRR is incomplete')
        );

        $metrics = $this->collect([
            Stripe::subscription([Stripe::item(unitAmount: 1000)]),
            Stripe::subscription([Stripe::item(usageType: 'metered')]),
        ]);

        $this->assertSame(1000, $metrics['mrr_cents']);
    }

    #[Test]
    public function it_can_be_told_to_ignore_unvaluable_items_entirely(): void
    {
        config()->set('mhh-telemetry.stripe.on_indeterminate_price', 'ignore');
        Log::shouldReceive('warning')->never();

        $metrics = $this->collect([
            Stripe::subscription([Stripe::item(unitAmount: 1000)]),
            Stripe::subscription([Stripe::item(usageType: 'metered')]),
        ]);

        $this->assertSame(1000, $metrics['mrr_cents']);
    }

    // --------------------------------------------------------- currency policy

    #[Test]
    public function it_refuses_to_pick_a_currency_for_you_when_none_is_pinned(): void
    {
        config()->set('mhh-telemetry.stripe.currency', null);

        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('multiple currencies');

        $this->collect([
            Stripe::subscription([Stripe::item(unitAmount: 1000, currency: 'eur')]),
            Stripe::subscription([Stripe::item(unitAmount: 2000, currency: 'usd')]),
        ]);
    }

    #[Test]
    public function it_refuses_to_report_zero_when_the_pinned_currency_has_no_revenue(): void
    {
        // Everything is in USD but EUR is pinned. Reporting "EUR 0" would be a
        // quietly wrong number, which is the whole thing this package avoids.
        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('no active EUR subscriptions');

        $this->collect([
            Stripe::subscription([Stripe::item(unitAmount: 2000, currency: 'usd')]),
        ]);
    }

    #[Test]
    public function it_reports_euros_out_of_the_box(): void
    {
        // No currency configured in the test: the shipped default applies.
        $metrics = $this->collect([
            Stripe::subscription([Stripe::item(unitAmount: 1000, currency: 'eur')]),
        ]);

        $this->assertSame('eur', $metrics['currency']);
        $this->assertSame(1000, $metrics['mrr_cents']);
    }

    #[Test]
    public function it_reports_the_pinned_currency_and_warns_about_the_rest(): void
    {
        config()->set('mhh-telemetry.stripe.currency', 'EUR');
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'USD')
        );

        $metrics = $this->collect([
            Stripe::subscription([Stripe::item(unitAmount: 1000, currency: 'eur')]),
            Stripe::subscription([Stripe::item(unitAmount: 2000, currency: 'usd')]),
        ]);

        $this->assertSame(1000, $metrics['mrr_cents']);
        $this->assertSame('eur', $metrics['currency']);
        $this->assertSame(['eur' => 1000, 'usd' => 2000], $metrics['mrr_by_currency']);
    }

    #[Test]
    public function it_keeps_the_pinned_currency_on_an_empty_account(): void
    {
        config()->set('mhh-telemetry.stripe.currency', 'eur');

        $metrics = $this->collect([]);

        $this->assertSame('eur', $metrics['currency']);
        $this->assertSame(0, $metrics['mrr_cents']);
    }

    #[Test]
    public function it_honours_the_configured_status_filter_through_the_source(): void
    {
        // The source decides which statuses to fetch; the collector counts what
        // it is given, so trialing subscriptions arrive only if configured.
        $metrics = $this->collect([
            Stripe::subscription([Stripe::item(unitAmount: 1000)], ['status' => 'trialing']),
        ]);

        $this->assertSame(1000, $metrics['mrr_cents']);
    }
}
