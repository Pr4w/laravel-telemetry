<?php

namespace Pr4w\Telemetry\Stripe;

use Illuminate\Contracts\Config\Repository;
use Pr4w\Telemetry\Exceptions\CollectorException;
use Stripe\StripeClient;

/**
 * Builds the Stripe client shared by every Stripe collector, so the secret
 * lookup and the pinned API version live in exactly one place.
 */
class StripeClientFactory
{
    /**
     * Pinned so upgrading stripe-php can't quietly change the response shape
     * underneath the calculations. Matches what stripe-php 17 ships with.
     */
    private const DEFAULT_API_VERSION = '2025-08-27.basil';

    public function __construct(
        private readonly Repository $config,
    ) {
    }

    public function make(): StripeClient
    {
        if (! class_exists(StripeClient::class)) {
            throw new CollectorException(
                'The Stripe collectors need stripe/stripe-php. Install it, or remove them '
                .'from [mhh-telemetry.collectors].'
            );
        }

        // Same places the original script looked: Cashier first, then the
        // plain STRIPE_SECRET every app already has. All read through config,
        // because env() returns null once the app runs config:cache.
        $secret = $this->config->get('cashier.secret')
            ?: $this->config->get('services.stripe.secret')
            ?: $this->config->get('mhh-telemetry.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new CollectorException(
                'No Stripe secret key found. Expected cashier.secret, services.stripe.secret, or STRIPE_SECRET.'
            );
        }

        return new StripeClient([
            'api_key' => $secret,
            'stripe_version' => $this->config->get('mhh-telemetry.stripe.api_version', self::DEFAULT_API_VERSION),
        ]);
    }
}
