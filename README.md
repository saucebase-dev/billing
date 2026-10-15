# Saucebase Billing

<div align="center">

[![Tests](https://github.com/saucebase-dev/billing/actions/workflows/test.yml/badge.svg)](https://github.com/saucebase-dev/billing/actions/workflows/test.yml)
[![Release](https://img.shields.io/github/v/release/saucebase-dev/billing)](https://github.com/saucebase-dev/billing/releases)
[![Saucebase Core](https://img.shields.io/packagist/dependency-v/saucebase/billing/saucebase%2Fcore?label=Saucebase%20Core&color=FF6B35)](https://github.com/saucebase-dev/core)
[![License](https://img.shields.io/github/license/saucebase-dev/billing)](LICENSE)

</div>

Stripe billing for Saucebase: checkout, subscriptions, trials, a pricing page and invoices.

A module for [Saucebase](https://github.com/saucebase-dev/saucebase), the AI-ready Laravel SaaS kit. Modules are Composer packages you add to a Saucebase app; once installed, the code is yours to change.

## Features

- **Stripe checkout**, straight to Stripe or through your own checkout page
- **Subscriptions** that cancel at period end and can be resumed
- **Customer portal** for cards, invoices and plan changes
- **Free trials**, one per customer, with or without a card up front
- **Grace period** after a failed payment, with emails at each step
- **Plan kinds**: free, subscription, lifetime and one-off
- **Entitlements**: each plan turns features on and sets limits your app checks
- **Pricing page** at `/pricing`, built from your plans
- **Billing settings**: current plan, invoices and saved card
- **Catalogue sync** with Stripe, both ways
- **Webhooks** that are safe to retry and handle events out of order
- **Emails** for every subscription and payment change
- **Admin panel** for products, subscriptions and customers, with a revenue dashboard
- **Vue and React**

## Install

**New to Saucebase?** Start a new app and pick **billing** when it asks for modules:

```bash
curl -fsSL https://install.saucebase.dev | bash
```

It installs PHP and Composer if they're missing. With the CLI already installed, run `saucebase new my-app` instead.

**Already have a Saucebase app?** Add it with:

```bash
composer require saucebase/billing
git apply modules/billing/patches/user.patch
php artisan migrate
php artisan modules:seed --module=billing
npm run build
```

Then create your plans in Stripe and import them with `php artisan billing:sync-catalog`. They arrive hidden: describe them in the admin panel, under **Billing → Products**, and turn on **Visible**.

### Manual install

If a patch doesn't apply, because your file has changed, make its change by hand:

**`user.patch`** makes users billing owners, which subscriptions need:

```php
// app/Models/User.php
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Traits\Billable;

class User extends Authenticatable implements BillingOwner
{
    use Billable;
}
```

## Configuration

Add your [Stripe keys](https://dashboard.stripe.com/apikeys) to `.env`:

```env
STRIPE_SECRET_KEY=
STRIPE_PUBLISHABLE_KEY=
STRIPE_WEBHOOK_SECRET=
```

In Stripe, under **Developers → Webhooks**, add `https://<your-app>/billing/webhooks/stripe` with these events. Its signing secret is `STRIPE_WEBHOOK_SECRET`.

```
checkout.session.completed
checkout.session.async_payment_succeeded
customer.subscription.updated
customer.subscription.deleted
customer.subscription.trial_will_end
invoice.paid
invoice.payment_succeeded
invoice.payment_failed
charge.refunded
payment_method.attached
payment_method.detached
customer.updated
```

Locally, forward them with `stripe listen --all-snapshot --forward-to localhost/billing/webhooks/stripe`.

The rest is in the admin panel, under **Settings → Billing**: currency, checkout flow, grace period and trials.

## Demo data

```bash
php artisan modules:seed --module=billing --demo
```

Adds sample plans (pushed to Stripe when your keys are set), sample customers, and a staff account (`billing@saucebase.dev`) that only sees billing in the admin. All use the `DEMO_PASSWORD` from `.env`.

## License

MIT. See [LICENSE](LICENSE).
