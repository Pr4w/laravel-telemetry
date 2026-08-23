<?php

namespace Pr4w\Telemetry\Stripe;

/**
 * The outcome of an MRR calculation: monthly totals per currency, plus every
 * subscription item whose contribution could not be determined.
 *
 * Issues are data, not exceptions. The caller decides whether an incomplete
 * total should fail the run, warn, or pass silently.
 */
final readonly class MrrResult
{
    /**
     * @param  array<string, int>  $totals  currency code => monthly cents
     * @param  list<array{subscription: string, item: string|null, reason: string}>  $issues
     */
    public function __construct(
        public array $totals,
        public array $issues,
        public int $subscriptionCount,
    ) {
    }

    /**
     * @return list<string>
     */
    public function currencies(): array
    {
        return array_keys($this->totals);
    }

    public function total(string $currency): int
    {
        return $this->totals[strtolower($currency)] ?? 0;
    }

    public function isComplete(): bool
    {
        return $this->issues === [];
    }

    /**
     * A one-line-per-issue summary suitable for logs and console output.
     *
     * @return list<string>
     */
    public function issueSummary(): array
    {
        return array_map(
            fn (array $issue): string => trim(sprintf(
                '%s%s: %s',
                $issue['subscription'],
                $issue['item'] !== null ? ' / '.$issue['item'] : '',
                $issue['reason'],
            )),
            $this->issues,
        );
    }
}
