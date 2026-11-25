# mhh-telemetry

Laravel package. Pushes the user count, Stripe MRR and Stripe balance, four times a day, from each SaaS app to the central
dashboard at `markhadjhamou.com/telemetry`.

Each app runs on its own Stripe account, so each app reports its own numbers. Replaces the
`app:send-telemetry` command that used to be copy-pasted into every project.

## Installing it in an app

It's not on Packagist, so point Composer at the GitHub repo:

```bash
composer config repositories.mhh-telemetry vcs https://github.com/Pr4w/laravel-telemetry
```

Then require it. Use a tagged version, not `dev-main`, so deploys are reproducible:

```bash
composer require pr4w/laravel-telemetry:^1.0
```

Commit the resulting `composer.json` and `composer.lock`. Nothing else is needed — no token, no
`auth.json`, no change to the deploy command.

> **The repo is public on purpose.** Composer fetches a VCS package as a zip from
> `api.github.com/repos/.../zipball/...`, and on a *private* repo that URL needs a GitHub API token —
> an SSH deploy key does not authenticate to the API, so deploys fail with a 404 and Composer 2.10
> will not fall back to git (source fallback is off by default and enabling it is deprecated). Public
> avoids the whole problem. Nothing here is sensitive: the only secret is each app's
> `TELEMETRY_SECRET`, which lives in that app's `.env` and never in this package.
>
> If it ever has to go private, give every deploy server a token with `repo` scope
> (`composer config --global github-oauth.github.com <token>`). Forcing a git clone with
> `config.preferred-install` also works, but breaks the moment a deploy script passes `--prefer-dist`,
> which Forge and Ploi do by default.

Then one environment variable:

```env
TELEMETRY_SECRET=<the app's api_token from the hub>
```

That's the whole install. The hub identifies the app by that token, the endpoint defaults to the hub,
the Stripe key comes from Cashier, and the schedule registers itself.

Check it before waiting for the cron:

```bash
php artisan mhh-telemetry:send --dry-run
```

### Releasing a new version

```bash
git tag v1.0.1 && git push --tags
```

Then in each app, `composer update pr4w/laravel-telemetry`. Nothing auto-updates — apps stay on the
tag they resolved until you tell them otherwise.

If you're actively changing the package and want an app to track it live, `composer require
pr4w/laravel-telemetry:dev-main` instead. Don't leave a production app on `dev-main`.

## Scheduling

Automatic. The service provider registers the command on Laravel's scheduler, so there is **nothing
to add to `routes/console.php`**. It runs four times a day — 00:00, 06:00, 12:00 and 18:00 in the app's
timezone — with `withoutOverlapping()`. Change it with `TELEMETRY_SCHEDULE_CRON`, e.g. `0 3 * * *` for
once a day.

Each run sends a fresh snapshot. Whether the dashboard keeps all four depends on the hub: keyed on the
day, each send overwrites the previous one; keyed on the hour, you get four points a day.

An app without `TELEMETRY_SECRET` schedules nothing: the package stays dormant until the app has been
set up on the hub, instead of failing on the scheduler four times a day. A manual
`php artisan mhh-telemetry:send` still says exactly what is missing.

The one prerequisite is the standard Laravel cron entry on the server, which you already need for
anything else scheduled:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Confirm it registered:

```bash
php artisan schedule:list
```

To take it over yourself, set `TELEMETRY_SCHEDULE=false` and call
`$schedule->command('mhh-telemetry:send')` wherever you prefer.

## Usage

```bash
php artisan mhh-telemetry:send            # what the scheduler runs
php artisan mhh-telemetry:send --dry-run  # collect and print, send nothing
```

Non-zero exit code on any failure, so a broken hub shows up as a failed scheduled task rather than a
dashboard that quietly goes stale. The reason is reported to the exception handler as well, so the
error tracker shows what failed, not just Laravel's generic "failed with exit code [1]".

## The payload

```json
{
  "schema_version": 1,
  "sent_at": "2026-08-23T03:00:00+00:00",
  "total_users": 1284,
  "mrr_cents": 419900,
  "currency": "eur",
  "mrr_by_currency": { "eur": 419900 },
  "active_subscriptions": 96,
  "stripe_balance_cents": 128450
}
```

Sent as `POST` with `X-App-Token: <secret>`. There is deliberately no app name in the body — the hub
resolves the `Website` from the token, so a name would be a second source of truth for something
already settled.

`total_users` and `mrr_cents` are at the top level, exactly where the old script put them, so the hub
needs no changes. The rest is extra context the hub's `validate()` currently discards.

## MRR

Read from the Stripe API rather than the local Cashier tables, because Cashier doesn't know about
coupons applied in the Stripe dashboard. Every subscription item is normalised to a month
(`year` ÷ 12, `week` × 52 ÷ 12, `day` × 365 ÷ 12, all divided by `interval_count`).

Beyond that, versus the old script:

- **Discounts are applied**, at both subscription and item level. A fixed-amount coupon is normalised
  against the billing interval, so a €12/year coupon reduces MRR by €1, not €12. `once` coupons are
  skipped — they aren't part of the run rate.
- **Tiered and metered prices no longer count as €0.** The old `unit_amount * quantity` silently
  produced zero when `unit_amount` was null. Now the run fails and names the subscriptions.
- **Currencies are never summed**, since cents aren't comparable across them. Reporting is in EUR;
  anything else is tracked separately in `mrr_by_currency` and logged. If EUR revenue ever hits zero
  while another currency has subscriptions, the run fails rather than report `0`.
- **Rounding happens once at the end**, not per subscription.
- **Subscriptions with more than 10 items** are fully paginated. Stripe caps the nested list at 10.

## Stripe balance

`stripe_balance_cents` is what is sitting in Stripe right now: available plus pending, in the same
currency as MRR. One API call per run.

It drops to near zero at every payout to your bank, so on a chart it is a sawtooth rather than a
trend — useful for "what's waiting", not for growth. It can also go negative when refunds or disputes
exceed incoming payments, and is reported as-is. Amounts in another currency are left out and logged
as a warning, never added in.

The hub has to store it explicitly: a column, and a validation rule *without* `min:0`, since a
negative balance is legitimate:

```php
'stripe_balance_cents' => ['required', 'integer'],
```

If the app uses a restricted Stripe key, it needs read access to Balance as well as Subscriptions —
otherwise the whole run fails, MRR included.

## Config

Only publish it if you need to change something:

```bash
php artisan vendor:publish --tag=mhh-telemetry-config
```

| Key | Default | |
| --- | --- | --- |
| `enabled` | `true` | Set `TELEMETRY_ENABLED=false` on staging. |
| `endpoint` | the hub | Override with `TELEMETRY_ENDPOINT`. |
| `secret` | — | `TELEMETRY_SECRET`. The only required value; without it, nothing is scheduled. |
| `collectors` | users, MRR, balance | See below. |
| `user_model` | auth provider's model | Only if the User model is somewhere unusual. |
| `stripe.secret` | `STRIPE_SECRET` | Fallback only; Cashier is checked first. |
| `stripe.api_version` | `2025-08-27.basil` | Pinned so a stripe-php upgrade can't shift the response shape. |
| `stripe.statuses` | `['active']` | Add `trialing` or `past_due` to count those. |
| `stripe.currency` | `eur` | `TELEMETRY_CURRENCY` for an app billing in something else. |
| `stripe.on_indeterminate_price` | `fail` | `warn` or `ignore` to report anyway. |
| `schedule.enabled` | `true` | `TELEMETRY_SCHEDULE=false` to run it yourself. |
| `schedule.cron` | `0 */6 * * *` | Four times a day. `TELEMETRY_SCHEDULE_CRON` to change it. |

The Stripe key resolves `cashier.secret` → `services.stripe.secret` → `stripe.secret`, which is
wherever the app already keeps it.

## Extra metrics per app

Implement `Collector` and add it to the list. It returns a flat array merged into the payload; two
collectors returning the same key is an error rather than one silently winning.

```php
use Pr4w\Telemetry\Contracts\Collector;

class ActiveWorkspaces implements Collector
{
    public function collect(): array
    {
        return ['active_workspaces' => Workspace::whereActive(true)->count()];
    }
}
```

```php
'collectors' => [
    UserCountCollector::class,
    StripeMrrCollector::class,
    StripeBalanceCollector::class,
    ActiveWorkspaces::class,
],
```

Resolved from the container, so constructor injection works. Throw from `collect()` if the metric
can't be produced accurately — the command fails rather than report a wrong number.

## Removing the old command

Delete `app/Console/Commands/SendTelemetry.php` and, if it was registered by hand, its
`$schedule->command('app:send-telemetry')` line.

## Development

Laravel 12 and 13, PHP 8.2+.

```bash
composer install
composer test
```
