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
- **Plan changes** — subscribers switch plans in Stripe's portal, and the app follows
- **One plan per customer** — checkout refuses a second subscription
- **Free trials** — set a trial length per plan; each customer gets one trial ever, with or without payment details up front
- **Grace period** — a failed payment keeps the subscription working for a few days, then suspends it, with an email at each step
- **Entitlements** — each plan turns features on and sets limits your app checks
- **Plan kinds** — free, subscription, lifetime and one-off plans
- **Lifetime deals** — a one-time plan that replaces a subscription plan for good; a full refund takes it back
- **Pricing page** — a public `/pricing` built from your plans, with monthly, yearly and one-time prices, discount badges, and "Contact sales" plans
- **Billing settings** — current plan, invoices and saved card at `/settings/billing`
- **Catalogue sync** — pull your plans from Stripe, or push plans you drafted in the admin up to Stripe
- **Webhooks** — safe to retry, and handles events arriving out of order
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

### 1. Make users billing owners

Required. Without it, subscriptions will not work.

```bash
git apply modules/billing/patches/user.patch
```

Or add it yourself in `app/Models/User.php`:

```php
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Traits\Billable;

class User extends Authenticatable implements BillingOwner
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

Send these events:

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

Stripe gives you a signing secret. That is the `STRIPE_WEBHOOK_SECRET` above.

For local development, forward the events instead:

```bash
stripe listen --events checkout.session.completed,checkout.session.async_payment_succeeded,customer.subscription.updated,customer.subscription.deleted,customer.subscription.trial_will_end,invoice.paid,invoice.payment_succeeded,invoice.payment_failed,charge.refunded,payment_method.attached,payment_method.detached,customer.updated \
  --forward-to localhost/billing/webhooks/stripe
```

### 4. Import your plans

Create your products and prices in Stripe, then run:

```bash
php artisan billing:sync-catalog
```

Plans arrive hidden. Go to `/admin` → Billing → Products, add a description and features, turn on *Visible*, and the plan shows up on your pricing page.

Stripe owns the name, price, currency and interval. Anything you write in the admin is yours and is never overwritten.

If you drafted your plans in the admin first, `php artisan billing:push-catalog` creates them in Stripe for you.

### 5. Turn on plan changes in Stripe

Subscribers change plan in Stripe's customer portal, so Stripe needs to know what they can switch to.

In Stripe, go to **Settings → Billing → Customer portal**, turn on *Customers can switch plans*, and add the products they can pick.

### 6. Run the scheduler

The module schedules its own jobs, but Laravel only runs them if the scheduler is running. On a server, add the usual cron entry:

```
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

Locally, run `php artisan schedule:work`.

| Command | Runs | What happens if it doesn't |
| --- | --- | --- |
| `billing:end-grace-periods` | hourly | A customer who never pays keeps their subscription after the grace period |
| `billing:expire-checkout-sessions` | every 30 minutes | Abandoned checkouts stay pending and keep holding the customer's trial |
| `billing:sync-catalog` | daily | Price changes made in Stripe only arrive when you sync by hand |

### Block double subscriptions (optional)

The app refuses a second subscription. It cannot stop one case: a customer who opens two checkouts at the same time and pays both.

To close that gap, go to **Settings → Checkout** in Stripe and turn on *Limit customers to one subscription*.

### Sample data (optional)

```bash
php artisan modules:seed --module=billing --demo
```

Adds five sample plans (Free, Pro, Team, Lifetime and Enterprise) plus demo customers and subscriptions so you can look around. If your Stripe keys are set, the plans are pushed to Stripe so they can be bought. Do not run this in production.

## Check access

Give each plan its features and limits in the admin (**Billing → Products → Plan**). Then ask the user:

```php
if ($user->canUseFeature('exports')) {
    // ...
}

$user->planLimit('projects'); // null means unlimited
```

Everyone gets the free plan's entitlements. A subscription or lifetime plan adds its own on top. Changing a plan's entitlements applies to its existing customers straight away.

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

Everything else is in the admin, at **`/admin` → Settings → Billing**: which gateway to use, your currency, whether checkout goes straight to Stripe, how long an unfinished checkout stays open, how many days of grace a failed payment gets, and whether a trial collects payment details first. Only your Stripe keys live in `.env`.

A plan's trial length is set on the plan itself, under **Products → Plan → Free trial**.

For anything beyond this — adding a gateway, customising the checkout and pricing pages, how webhooks and syncing work — see the [documentation](https://saucebase-dev.github.io/docs/modules/billing).

## License

Proprietary. Part of [Saucebase](https://github.com/saucebase-dev/saucebase).
