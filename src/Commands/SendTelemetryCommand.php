<?php

namespace Pr4w\Telemetry\Commands;

use Illuminate\Console\Command;
use Pr4w\Telemetry\Exceptions\TelemetryException;
use Pr4w\Telemetry\Telemetry;
use Throwable;

class SendTelemetryCommand extends Command
{
    protected $signature = 'mhh-telemetry:send
                            {--dry-run : Collect and print the payload without sending it}';

    protected $description = 'Collect and push daily telemetry to the central dashboard';

    public function handle(Telemetry $telemetry): int
    {
        if (! $telemetry->enabled()) {
            $this->components->info('Telemetry is disabled; nothing to do.');

            return self::SUCCESS;
        }


        try {
            $payload = $telemetry->payload();
        } catch (Throwable $e) {
            return $this->reportFailure('Could not collect telemetry', $e);
        }

        $this->render($payload);

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run - nothing was sent.');

            return self::SUCCESS;
        }

        try {
            $telemetry->send($payload);
        } catch (Throwable $e) {
            return $this->reportFailure('Could not deliver telemetry', $e);
        }

        $this->components->info('Telemetry sent.');

        return self::SUCCESS;
    }

    /**
     * A misconfiguration is the operator's to fix and gets a clean one-line
     * message. Anything else - a dropped database connection, a Stripe outage -
     * also goes to the exception handler, so the stack trace reaches the logs
     * instead of only scrolling past in cron output.
     */
    private function reportFailure(string $context, Throwable $e): int
    {
        if (! $e instanceof TelemetryException) {
            report($e);
        }

        $this->components->error($context.': '.$e->getMessage());

        return self::FAILURE;
    }


    /**
     * @param  array<string, mixed>  $payload
     */
    private function render(array $payload): void
    {
        $rows = [];

        foreach ($payload as $key => $value) {
            $rows[] = [$key, $this->displayValue($key, $value)];
        }

        $this->table(['Field', 'Value'], $rows);
    }

    private function displayValue(string $key, mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }

        // Money is stored in cents; show the human figure next to it so a
        // misplaced factor of 100 is obvious at a glance.
        if (str_ends_with($key, '_cents') && is_int($value)) {
            return sprintf('%d (%s)', $value, number_format($value / 100, 2));
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '-',
            default => (string) $value,
        };
    }
}
