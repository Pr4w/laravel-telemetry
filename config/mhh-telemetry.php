<?php

use Pr4w\Telemetry\Collectors\StripeBalanceCollector;
use Pr4w\Telemetry\Collectors\StripeMrrCollector;
use Pr4w\Telemetry\Collectors\UserCountCollector;

return [

    /*
    | Set false on staging so it doesn't pollute the dashboard. The command
    | then exits successfully without collecting anything.
    */

    'enabled' => env('TELEMETRY_ENABLED', true),

    /*
    | The hub identifies the app by its token, so the secret below is the only
    | value a new app has to set.
    */

    'endpoint' => env('TELEMETRY_ENDPOINT', 'https://markhadjhamou.com/telemetry'),

    'secret' => env('TELEMETRY_SECRET'),

    /*
    | Each collector returns a flat array of metrics merged into the payload.
    | Add your own by implementing Pr4w\Telemetry\Contracts\Collector.
    */

    'collectors' => [
        UserCountCollector::class,
        StripeMrrCollector::class,
        StripeBalanceCollector::class,
    ],

    /*
    | Defaults to the model behind the default auth provider. Only set this if
    | your User model lives somewhere unusual.
    */

    'user_model' => null,

    'stripe' => [

        /*
        | Only consulted if the app has neither Cashier nor services.stripe
        | configured - normally one of those already holds the key. Read here
        | rather than via env() at runtime, which returns null once the config
        | is cached.
        */

        'secret' => env('STRIPE_SECRET'),

        /*
        | Pinned explicitly so upgrading stripe-php can't quietly change the
        | response shape underneath the MRR calculation.
        */

        'api_version' => '2025-08-27.basil',

        /*
        | Which subscription statuses count toward MRR. Add 'trialing' to count
        | trials, or 'past_due' to count subscriptions still in dunning.
        */

        'statuses' => ['active'],

        /*
        | The currency reported as `mrr_cents`. Totals in any other currency are
        | tracked separately in `mrr_by_currency` and logged as a warning, never
        | summed into this one.
        */

        'currency' => env('TELEMETRY_CURRENCY', 'eur'),

        /*
        | What to do when an item's monthly value can't be derived - tiered
        | pricing, metered usage, a missing amount. 'fail' is the default
        | because a number that's quietly too low is worse than a red cron job.
        | One of: fail, warn, ignore.
        */

        'on_indeterminate_price' => 'fail',

    ],

    'schedule' => [
        'enabled' => env('TELEMETRY_SCHEDULE', true),

        /*
        | Four times a day: 00:00, 06:00, 12:00 and 18:00 in the app's timezone.
        | Any cron expression works - '0 3 * * *' for once a day.
        */

        'cron' => env('TELEMETRY_SCHEDULE_CRON', '0 */6 * * *'),
    ],

];
