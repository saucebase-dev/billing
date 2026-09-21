# Billing Module

<div align="center">

[![Tests](https://github.com/saucebase-dev/billing/actions/workflows/test.yml/badge.svg)](https://github.com/saucebase-dev/billing/actions/workflows/test.yml)
[![Release](https://img.shields.io/github/v/release/saucebase-dev/billing)](https://github.com/saucebase-dev/billing/releases)
[![Saucebase](https://img.shields.io/badge/Saucebase-1.1+-FF6B35)](https://github.com/saucebase-dev/saucebase)
[![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)](https://php.net)
[![Stripe](https://img.shields.io/badge/Stripe-API-635BFF?logo=stripe&logoColor=white)](https://stripe.com)

Works with:<br/>
[![Vue 3.5](https://img.shields.io/badge/Vue-3.5-4FC08D?logo=vue.js&logoColor=white)](https://vuejs.org) [![React 19](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)](https://react.dev)

</div>

Stripe subscriptions and checkout for [Saucebase](https://github.com/saucebase-dev/saucebase), a Laravel SaaS starter kit.

Adds a pricing page, a checkout, a billing settings page, and an admin panel for your plans and customers. Your plans live in Stripe and sync into the app.

**[Full documentation →](https://saucebase-dev.github.io/docs/modules/billing)**

## Features

- **Stripe checkout** — send buyers straight to Stripe, or use the module's own checkout page first
- **Subscriptions** — cancel at the end of the period, resume before it runs out
- **Customer portal** — one click to Stripe's portal, where customers update their card and download invoices
- **Pricing page** — a public `/pricing` built from your plans, with monthly and yearly switching
- **Billing settings** — current plan, invoices and saved card at `/settings/billing`
- **Catalogue sync** — pull your plans from Stripe, or push plans you drafted in the admin up to Stripe
- **Webhooks** — safe to retry, and handles events arriving out of order
- **Subscriber role** — added and removed as subscriptions start and end
- **Admin panel** — manage products, prices, subscriptions and customers, with a revenue dashboard
- **Events** — hook your own code into every subscription and payment change
- **Vue and React** — every screen works on both

## Requirements

| | |
| --- | --- |
| Saucebase core | `^1.1` |
| Modules | [Auth](https://github.com/saucebase-dev/auth) |
| Service | A [Stripe](https://stripe.com) account |

## Installation

```bash
composer require saucebase/billing
php artisan migrate
npm run build
```

### 1. Add the Billable trait

Required. Without it, subscriptions will not work.

```bash
git apply modules/billing/patches/user.patch
```

Or add it yourself in `app/Models/User.php`:

```php
use Modules\Billing\Traits\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

### 2. Add your Stripe keys

Copy them from your [Stripe dashboard](https://dashboard.stripe.com/apikeys) into `.env`:

```env
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

### 3. Set up the webhook

In Stripe, go to **Developers → Webhooks** and add this URL:

```
https://your-app.com/billing/webhooks/stripe
```

Stripe gives you a signing secret. That is the `STRIPE_WEBHOOK_SECRET` above.

For local development, forward the events instead:

```bash
stripe listen --forward-to localhost/billing/webhooks/stripe
```

### 4. Import your plans

Create your products and prices in Stripe, then run:

```bash
php artisan billing:sync-catalog
```

Plans arrive hidden. Go to `/admin` → Billing → Products, add a description and features, turn on *Visible*, and the plan shows up on your pricing page.

Stripe owns the name, price, currency and interval. Anything you write in the admin is yours and is never overwritten.

If you drafted your plans in the admin first, `php artisan billing:push-catalog` creates them in Stripe for you.

### Sample data (optional)

```bash
php artisan modules:seed --module=billing --demo
```

Adds three plans plus demo customers and subscriptions so you can look around. If your Stripe keys are set, the plans are pushed to Stripe so they can be bought. Do not run this in production.

## Extending

You can build on the module without editing it.

**Listen to events.** Every subscription and payment change fires one, so you can send your own emails, update your own tables, or call another service:

```php
use Modules\Billing\Events\SubscriptionCreated;

Event::listen(SubscriptionCreated::class, function (SubscriptionCreated $event) {
    // $event->subscription
});
```

Available: `CheckoutCompleted`, `SubscriptionCreated`, `SubscriptionUpdated`, `SubscriptionCancelled`, `SubscriptionResumed`, `PaymentSucceeded`, `PaymentFailed`, `InvoicePaid`.

**Change the screens.** The pricing page, checkout and billing settings are normal Vue and React pages in `resources/js/`. Edit them like any other page in your app.

**Add a gateway.** Stripe is the only one today, and parts of the module still talk to Stripe directly, so a second provider is not a drop-in yet. `PaymentGatewayInterface` covers the outgoing calls; the webhook handling would need work first.

## Configuration

Everything else is in the admin, at **`/admin` → Settings → Billing**: which gateway to use, your currency, whether checkout goes straight to Stripe, and how long an unfinished checkout stays open. Only your Stripe keys live in `.env`.

For anything beyond this — adding a gateway, customising the checkout and pricing pages, how webhooks and syncing work — see the [documentation](https://saucebase-dev.github.io/docs/modules/billing).

## License

Proprietary. Part of [Saucebase](https://github.com/saucebase-dev/saucebase).
