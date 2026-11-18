<?php

namespace Pr4w\Telemetry\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pr4w\Telemetry\Collectors\UserCountCollector;
use Pr4w\Telemetry\Exceptions\CollectorException;
use Pr4w\Telemetry\Tests\Fixtures\SoftDeletingUser;
use Pr4w\Telemetry\Tests\Fixtures\User;
use Pr4w\Telemetry\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class UserCountCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->boolean('is_internal')->default(false);
            $table->softDeletes();
        });

        User::insert([
            ['email' => 'a@example.com', 'is_internal' => false],
            ['email' => 'b@example.com', 'is_internal' => false],
            ['email' => 'internal@example.com', 'is_internal' => true],
        ]);

        config()->set('mhh-telemetry.user_model', User::class);
    }

    private function collect(): array
    {
        return $this->app->make(UserCountCollector::class)->collect();
    }

    #[Test]
    public function it_counts_users(): void
    {
        $this->assertSame(['total_users' => 3], $this->collect());
    }

    #[Test]
    public function it_resolves_the_model_from_the_auth_config_when_none_is_set(): void
    {
        config()->set('mhh-telemetry.user_model', null);
        config()->set('auth.providers.users.model', User::class);

        $this->assertSame(['total_users' => 3], $this->collect());
    }

    // ------------------------------------------------------------ soft deletes

    #[Test]
    public function it_leaves_soft_deleted_users_out_of_the_count(): void
    {
        config()->set('mhh-telemetry.user_model', SoftDeletingUser::class);
        SoftDeletingUser::query()->where('email', 'a@example.com')->delete();

        // Churn has to be able to show up in the number, otherwise the chart
        // only ever goes up.
        $this->assertSame(['total_users' => 2], $this->collect());
        $this->assertSame(3, SoftDeletingUser::withTrashed()->count());
    }

    #[Test]
    public function it_can_be_told_to_count_them(): void
    {
        config()->set('mhh-telemetry.user_model', SoftDeletingUser::class);
        config()->set('mhh-telemetry.count_soft_deleted', true);
        SoftDeletingUser::query()->where('email', 'a@example.com')->delete();

        $this->assertSame(['total_users' => 3], $this->collect());
    }

    #[Test]
    public function the_flag_is_harmless_on_a_model_without_the_trait(): void
    {
        // withTrashed() does not exist without SoftDeletes and would throw, so
        // the flag has to be ignored rather than applied blindly.
        config()->set('mhh-telemetry.user_model', User::class);
        config()->set('mhh-telemetry.count_soft_deleted', true);

        $this->assertSame(['total_users' => 3], $this->collect());
    }

    #[Test]
    public function it_fails_loudly_when_the_model_cannot_be_resolved(): void
    {
        config()->set('mhh-telemetry.user_model', 'App\\Models\\NotHere');
        config()->set('auth.providers.users.model', null);

        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('mhh-telemetry.user_model');

        $this->collect();
    }
}
