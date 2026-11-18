<?php

namespace Pr4w\Telemetry\Tests\Feature;

use Pr4w\Telemetry\Contracts\SubscriptionSource;
use Pr4w\Telemetry\Exceptions\CollectorException;
use Pr4w\Telemetry\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ConfigDefaultsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function shippedConfig(): array
    {
        return require __DIR__.'/../../config/mhh-telemetry.php';
    }

    #[Test]
    public function a_new_app_only_has_to_set_the_secret(): void
    {
        $config = $this->shippedConfig();

        $this->assertSame('https://markhadjhamou.com/telemetry', $config['endpoint']);
        $this->assertTrue($config['enabled']);
        $this->assertNull($config['user_model']);
        $this->assertSame(['active'], $config['stripe']['statuses']);
        $this->assertSame('eur', $config['stripe']['currency']);
        $this->assertFalse($config['count_soft_deleted']);
        $this->assertSame('0 */6 * * *', $config['schedule']['cron']);
        $this->assertArrayNotHasKey('app', $config);
        $this->assertArrayHasKey('secret', $config['stripe']);
    }

    #[Test]
    public function the_stripe_api_version_is_pinned_to_a_real_version(): void
    {
        // Deliberately a literal, not \Stripe\Util\ApiVersion::CURRENT: the
        // whole point of pinning is that upgrading stripe-php does not move it.
        // Changing this string means re-checking the MRR fixtures against the
        // new version's response shape.
        $this->assertSame('2025-08-27.basil', $this->shippedConfig()['stripe']['api_version']);
    }

    #[Test]
    public function it_finds_the_stripe_key_where_the_app_already_keeps_it(): void
    {
        config()->set('cashier.secret', null);
        config()->set('services.stripe.secret', null);
        config()->set('mhh-telemetry.stripe.secret', null);

        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('cashier.secret, services.stripe.secret, or STRIPE_SECRET');

        // Building the client is what resolves the key, so the generator has to
        // be advanced at least once.
        iterator_to_array($this->app->make(SubscriptionSource::class)->all());
    }
}
