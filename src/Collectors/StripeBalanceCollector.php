<?php

namespace Pr4w\Telemetry\Collectors;

use Illuminate\Contracts\Config\Repository;
use Pr4w\Telemetry\Contracts\BalanceSource;
use Pr4w\Telemetry\Contracts\Collector;
use Psr\Log\LoggerInterface;

/**
 * Reports what is sitting in Stripe right now: available plus pending, in the
 * same currency as MRR.
 *
 * Not a growth metric - it drops to near zero at every payout - and it can be
 * negative when refunds or disputes exceed incoming payments, so it is reported
 * exactly as Stripe returns it.
 */
final class StripeBalanceCollector implements Collector
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
        private readonly BalanceSource $source,
    ) {
    }

    public function collect(): array
    {
        $currency = strtolower((string) ($this->config->get('mhh-telemetry.stripe.currency') ?: 'eur'));
        $balance = $this->source->balance();

        $total = 0;
        $excluded = [];

        foreach (['available', 'pending'] as $bucket) {
            foreach ($balance[$bucket] ?? [] as $entry) {
                $entryCurrency = strtolower((string) ($entry['currency'] ?? ''));
                $amount = (int) ($entry['amount'] ?? 0);

                if ($entryCurrency === $currency) {
                    $total += $amount;

                    continue;
                }

                // Cents in another currency are not comparable, so they are
                // never added in - but not left out silently either.
                if ($amount !== 0) {
                    $excluded[$entryCurrency] = ($excluded[$entryCurrency] ?? 0) + $amount;
                }
            }
        }

        if ($excluded !== []) {
            $this->logger->warning(sprintf(
                'Telemetry: reporting the Stripe balance in %s; left out %s.',
                strtoupper($currency),
                implode(', ', array_map(
                    fn (string $code, int $amount): string => strtoupper($code).' '.number_format($amount / 100, 2),
                    array_keys($excluded),
                    $excluded,
                )),
            ));
        }

        return ['stripe_balance_cents' => $total];
    }
}
