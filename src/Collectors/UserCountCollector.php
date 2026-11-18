<?php

namespace Pr4w\Telemetry\Collectors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
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
        return ['total_users' => $this->query()->count()];
    }

    private function query(): Builder
    {
        $model = $this->model();

        $query = $model::query();

        // A model using SoftDeletes already excludes trashed rows, via the
        // global scope Eloquent adds. That is the right default - a deleted
        // account is churn, and counting it would mean total_users could never
        // go down - but it is stated here so it is a decision rather than an
        // accident of the trait, and so the opt-out has somewhere to live.
        if ($this->countSoftDeleted() && $this->usesSoftDeletes($model)) {
            $query->withTrashed();
        }

        return $query;
    }

    /**
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    private function model(): string
    {
        $model = $this->config->get('mhh-telemetry.user_model')
            ?: $this->config->get('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model)) {
            throw new CollectorException(
                'Could not resolve the user model. Set [mhh-telemetry.user_model] to the fully qualified class name.'
            );
        }

        return $model;
    }

    private function countSoftDeleted(): bool
    {
        return (bool) $this->config->get('mhh-telemetry.count_soft_deleted', false);
    }

    /**
     * Checked before calling withTrashed(), which does not exist on a model
     * without the trait and would throw BadMethodCallException.
     */
    private function usesSoftDeletes(string $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
