<?php

namespace Pr4w\Telemetry\Collectors;

use Illuminate\Contracts\Config\Repository;
use Pr4w\Telemetry\Contracts\Collector;
use Pr4w\Telemetry\Exceptions\CollectorException;

/**
 * Counts application users.
 *
 * The model is resolved from the default auth provider rather than hardcoded,
 * so this works in apps that keep their User model somewhere other than
 * App\Models\User.
 */
final class UserCountCollector implements Collector
{
    public function __construct(
        private readonly Repository $config,
    ) {
    }

    public function collect(): array
    {
        $model = $this->config->get('mhh-telemetry.user_model')
            ?: $this->config->get('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model)) {
            throw new CollectorException(
                'Could not resolve the user model. Set [mhh-telemetry.user_model] to the fully qualified class name.'
            );
        }

        return ['total_users' => $model::query()->count()];
    }
}
