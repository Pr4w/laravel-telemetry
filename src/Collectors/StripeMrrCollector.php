<?php

namespace Pr4w\Telemetry\Collectors;

use Illuminate\Contracts\Config\Repository;
use Pr4w\Telemetry\Contracts\Collector;
use Pr4w\Telemetry\Contracts\SubscriptionSource;
use Pr4w\Telemetry\Exceptions\CollectorException;
use Pr4w\Telemetry\Stripe\MrrCalculator;
use Pr4w\Telemetry\Stripe\MrrResult;
use Psr\Log\LoggerInterface;

/**
 * Reports monthly recurring revenue straight from the billing provider, which
 * is the source of truth - the local Cashier tables know nothing about coupons
 * applied in the Stripe dashboard.
 */
final class StripeMrrCollector implements Collector
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
        private readonly SubscriptionSource $subscriptions,
    ) {
    }

    public function collect(): array
    {
        $result = (new MrrCalculator)->calculate($this->subscriptions->all());

        $this->reportIssues($result);

        [$currency, $total] = $this->resolveReportedTotal($result);

        return [
            'mrr_cents' => $total,
            'currency' => $currency,
            'mrr_by_currency' => $result->totals,
            'active_subscriptions' => $result->subscriptionCount,
        ];
    }

    /**
     * Apply the configured policy for subscriptions whose contribution could
     * not be determined. Defaulting to `fail` is deliberate: a number that is
     * quietly too low is worse than a red cron job.
     */
    private function reportIssues(MrrResult $result): void
    {
        if ($result->isComplete()) {
            return;
        }

        $policy = $this->config->get('mhh-telemetry.stripe.on_indeterminate_price', 'fail');

        if ($policy === 'ignore') {
            return;
        }

        $summary = $result->issueSummary();

        $message = sprintf(
            'Telemetry: MRR is incomplete, %d subscription item(s) could not be valued: %s',
            count($summary),
            implode('; ', array_slice($summary, 0, 10)),
        );

        if ($policy === 'warn') {
            $this->logger->warning($message);

            return;
        }

        throw new CollectorException(
            $message.' Set [mhh-telemetry.stripe.on_indeterminate_price] to "warn" to report anyway.'
        );
    }

    /**
     * Cents are not comparable across currencies, so we never sum them. Report
     * one currency's total as `mrr_cents` and ship the full breakdown alongside.
     *
     * @return array{0: string|null, 1: int}
     */
    private function resolveReportedTotal(MrrResult $result): array
    {
        $configured = $this->config->get('mhh-telemetry.stripe.currency');
        $configured = is_string($configured) && $configured !== '' ? strtolower($configured) : null;
        $currencies = $result->currencies();

        if ($currencies === []) {
            return [$configured, 0];
        }

        if (count($currencies) === 1 && ($configured === null || $configured === $currencies[0])) {
            return [$currencies[0], $result->total($currencies[0])];
        }

        if ($configured !== null) {
            // Reporting 0 because every subscription is in some other currency
            // is exactly the kind of quietly-wrong number this package exists
            // to avoid. Fail instead.
            if (! in_array($configured, $currencies, true)) {
                throw new CollectorException(sprintf(
                    'Telemetry: [mhh-telemetry.stripe.currency] is set to %s, but there are no active %s '
                    .'subscriptions - all revenue is in %s. Reporting it as %s 0 would be wrong.',
                    strtoupper($configured),
                    strtoupper($configured),
                    strtoupper(implode(', ', $currencies)),
                    strtoupper($configured),
                ));
            }

            $others = array_values(array_diff($currencies, [$configured]));

            if ($others !== []) {
                $this->logger->warning(sprintf(
                    'Telemetry: reporting MRR in %s; excluded totals in %s. See mrr_by_currency for the breakdown.',
                    strtoupper($configured),
                    strtoupper(implode(', ', $others)),
                ));
            }

            return [$configured, $result->total($configured)];
        }

        throw new CollectorException(sprintf(
            'Telemetry: active subscriptions span multiple currencies (%s), so there is no single correct MRR '
            .'figure. Set [mhh-telemetry.stripe.currency] to the one you want reported.',
            strtoupper(implode(', ', $currencies)),
        ));
    }
}
