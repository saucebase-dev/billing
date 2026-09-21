# Billing Module

Subscription management, checkout sessions, payment processing, and webhook handling via a payment gateway driver pattern (Stripe default).

## Key Files

| Layer | Files |
|-------|-------|
| Controllers | `CheckoutController` (create, show, store), `SubscriptionController` (cancel, resume), `BillingPortalController` (portal redirect), `SettingsBillingController` (show), `WebhookController` (invoke) |
| Models | `Customer`, `Subscription`, `Product`, `Price`, `Payment`, `PaymentMethod`, `Invoice`, `CheckoutSession`, `WebhookEvent` |
| Services | `BillingService` — main orchestrator (checkout, webhooks, cancel/resume, portal), `PaymentGatewayManager` — driver manager (extends Laravel's Manager), `CatalogSync` — pulls products/prices from the provider, `CatalogPush` — creates local-only ones there |
| Gateway | `StripeGateway` — implements `PaymentGatewayInterface` (create customer/session, cancel/resume, portal, webhook verify) |
| Enums | `SubscriptionStatus`, `PaymentStatus`, `CheckoutSessionStatus`, `InvoiceStatus`, `PaymentMethodType`, `Currency`, `BillingScheme`, `WebhookEventType` |
| Events | `SubscriptionCreated`, `SubscriptionUpdated`, `SubscriptionCancelled`, `SubscriptionResumed`, `PaymentSucceeded`, `PaymentFailed`, `InvoicePaid`, `CheckoutCompleted` |
| Listeners | `SyncSubscriberRole` (synchronous — entitlement), and the queued `SendSubscriptionCreatedNotification`, `SendSubscriptionUpdatedNotification`, `SendSubscriptionCancelledNotification`, `SendSubscriptionResumedNotification`, `SendPaymentSucceededNotification`, `SendPaymentFailedNotification` |
| Data | `CheckoutData`, `CheckoutResultData`, `WebhookData` (carries `occurredAt` for ordering), `CustomerData`, `AddressData`, `PaymentMethodData`, `PaymentMethodDetails` (Spatie Data objects) |
| Commands | `ExpireCheckoutSessionsCommand` (every 30 min, marks abandoned/expired sessions), `SyncCatalogCommand` (`billing:sync-catalog`, daily and on the admin's *Sync* button), `PushCatalogCommand` (`billing:push-catalog`, on the admin's *Push new* button). Both in `src/Console/Commands/`, which is where internachi discovers them |
| Middleware | `RedirectToRegister` — redirects guests on checkout pages, stores intended URL |
| Filament | `BillingPlugin`, `BillingDashboard` (date range stats), `ProductResource`, `SubscriptionResource`, `CustomerResource` |
| Trait | `Billable` — added to User model (`billingCustomer()` HasOne relationship) |
| Pages | `SettingsBilling`, `Checkout` |

## Frontend

Both stacks ship: `resources/js/vue/` and `resources/js/react/` hold the same four screens — `pages/Plans`, `pages/Checkout`, `pages/SettingsBilling` and `components/ProductCard`/`ProductSection` — plus a `CheckoutLayout`. Change one, change the other.

`resources/js/lib/intervals.ts` is framework-neutral and shared by both; it is the only place that knows `monthly` and `month` are the same interval.

Vue's `ProductSection` takes the heading through the default slot and the footer through a named one; React takes them as `children` and a `footer` prop. React has no `InputField`, so `Checkout.tsx` composes `Field`/`FieldLabel`/`Input` the way the auth module's panels do.

The React sources are not type-checked in contributor mode: the root `tsconfig.json` maps `@/*` to the Vue stack and React itself is only installed once a stack is selected. The same is true of the auth module's React files.

## Routes

**Checkout** (no auth required):
```
POST  /billing/checkout                      → billing.checkout.create  (throttle:10,1)
GET   /billing/checkout/{checkout_session}   → billing.checkout.show    (RedirectToRegister)
POST  /billing/checkout/{checkout_session}   → billing.checkout.store   (RedirectToRegister)
```

**Auth required**:
```
GET   /billing/portal                → billing.portal
POST  /billing/subscription/cancel   → billing.subscription.cancel
POST  /billing/subscription/resume   → billing.subscription.resume
GET   /settings/billing              → settings.billing
```

**Webhook** (no CSRF):
```
POST  /billing/webhooks/{provider}   → billing.webhooks
```

## Patterns

### Checkout Flow
`CheckoutController::create()` checks the price is purchasable (`Price::purchasable()` — active, on an active product), creates a `CheckoutSession` (status: Pending) and, with the `redirect_to_gateway` setting on, hands the signed-in buyer straight to the provider. With it off, `show()` renders the module's own checkout page and `store()` collects an email and coupon first. Both paths end in `BillingService::processCheckout()`, which:
1. Calls `ensureCustomer()` — finds or creates the `Customer` record and the Stripe customer
2. Claims the session for that customer in one statement, so a second user cannot take it over
3. Returns the stored `provider_url` if a hand-off already happened (the first one is the only one the buyer can pay); otherwise calls `StripeGateway::createCheckoutSession()` with an idempotency key so two racing requests get one Stripe session
4. Updates `CheckoutSession` with `provider_session_id`
5. Redirects via `Inertia::location()` to the Stripe-hosted page

On return, `SettingsBillingController::show()` checks for a `session_id` query param and calls `BillingService::fulfillCheckoutIfNeeded()` as a fallback (redirect-based completion in case the webhook hasn't fired yet). A session whose `payment_status` is `unpaid` (delayed payment method) is not fulfilled until `checkout.session.async_payment_succeeded` arrives; `no_payment_required` (trial, 100% discount) is fulfilled.

### Webhook Processing
`BillingService::handleWebhook()`:
1. Calls `gateway->verifyAndParseWebhook()` — signature verification, maps Stripe event type to `WebhookEventType` enum
2. Deduplicates by `(provider, provider_event_id)` in `webhook_events` — one atomic `UPDATE … WHERE processed_at IS NULL` claims the event, so concurrent deliveries cannot both run. A handler that throws hands the claim back (`processed_at` null), `WebhookController` answers 500, and Stripe's retry is processed rather than skipped. This is also how out-of-order delivery recovers: an invoice for a subscription that does not exist locally yet throws and is retried.

   Retrying only helps when the missing row is still on its way, so `subscriptionForEvent()` splits the two cases: a subscription the app cannot find **under a customer it knows** throws and lets the provider retry, while one whose customer is also unknown is logged and acknowledged. Nothing here ever described that subscription — a foreign account, or a database rebuilt without it — so a 500 would have the provider retry forever.
3. Routes to private handlers via match on `WebhookEventType`:
   - `CheckoutCompleted` → locks and re-reads the session, then creates Subscription, Payment, PaymentMethod and assigns the subscriber role **inside the same transaction** (a retry is a no-op once the session is Completed, so nothing after commit may own entitlement). Also mapped from `checkout.session.async_payment_succeeded`
   - `SubscriptionUpdated` → maps Stripe status to `SubscriptionStatus` (active/trialing → Active, past_due/unpaid → PastDue, canceled → Cancelled), syncs period dates. Applied through `applyIfCurrent()`: under a row lock, skipped if older than `last_event_at` or if the row is already Cancelled (Stripe never reactivates one). Provider calls happen before the lock
   - `SubscriptionDeleted` → marks Cancelled, fires `SubscriptionCancelled`. Like `SubscriptionUpdated`, it resolves the row through `subscriptionForEvent()` and acknowledges rather than fails when the customer is unknown
   - `PaymentSucceeded` → creates Payment, restores PastDue subscription to Active
   - `PaymentFailed` → creates Payment, marks subscription PastDue; ignored if that payment has since succeeded
   - `InvoicePaid` → creates/updates Invoice, syncs subscription period dates

### Subscription Lifecycle
Cancellation is always at period end by default. `SubscriptionController::cancel()` calls `BillingService::cancel()` which sets `cancelled_at` and `ends_at` on the Subscription. Resume undoes a scheduled cancellation — `gateway->resumeSubscription()` sets Stripe `cancel_at_period_end: false` and the timestamps are cleared locally. It cannot revive a subscription Stripe has already ended.

Status transitions driven by webhooks: Active → PastDue (failed payment) → Cancelled (subscription deleted or never recovered). A cancelled subscription can be resumed while `ends_at` is in the future.

### Catalog Sync
The provider is the source of truth for what is sold: `CatalogSync::run()` pulls `listCatalog()` from the gateway and upserts `Product`/`Price` keyed on `(provider, provider_product_id)` / `(provider, provider_price_id)`. It writes only the provider's fields — name, active, amount, currency, interval — and on first import sets `is_visible = false`, `sku` = provider product ID, takes the provider's description and marketing features as a starting point, and appends `display_order` after the highest one already in use so an import never lands a batch of products on the same rank. After that, description, features, slug, visibility, highlight and order are the app's and are never overwritten. Archived at the provider → `is_active = false`, never deleted (subscriptions point at the rows) — but one archived *and* unknown here is skipped entirely and counted in the report's `skipped`: a plan retired before this app saw it is history, not catalogue. Prices the provider has never heard of are left alone and listed in the report's `localOnly`.

`CatalogPush::run()` is the one-off in the other direction, for plans drafted in the admin before a provider account existed: every product/price with **no** provider ID is created at the provider and gets its ID written back. `run(Product $only)` scopes it to one product — that is the *Push to Stripe* action on the product row and edit page. It never updates anything the provider already knows (provider prices are immutable — change them there and pull), so it has no conflict to resolve. The one exception is the feature list, which `pushProductFeatures()` sends for every product on every run: the app owns a plan's marketing copy once it is here, and the provider's copy exists only so its own pricing pages can show it.

In the admin, `ProductForm` disables the provider's fields when `Product::provider_product_id` / `Price::isManagedByGateway()` is set, and hides the repeater's delete action for synced prices. A product or price with no provider ID (demo, test) stays fully editable.

### Role Syncing
`SyncSubscriberRole` listens to `SubscriptionCreated|SubscriptionUpdated|SubscriptionCancelled`. Assigns `Role::SUBSCRIBER` when the subscription status is Active or PastDue. Removes the role only if the user has no other active subscriptions (to handle multiple subscriptions).

### Gateway Driver Pattern
`PaymentGatewayManager` extends Laravel's `Manager`. The default driver is `billing.default_gateway` config. Adding a new gateway means implementing `PaymentGatewayInterface` and adding a `createXxxDriver()` method. The gateway talks to the provider and returns identifiers; `BillingService` owns every local row (`createCustomer()` returns the provider's customer ID, the service creates the `Customer`). `BillingService` is not yet provider-neutral: the webhook handlers read Stripe payload keys directly, and `fulfillCheckoutIfNeeded()`, `ensurePaymentMethod()` and `syncSubscriptionPeriod()` branch on `instanceof StripeGateway`. A second provider needs those moved behind the interface first.

### Payment Correlation
Payments are matched by provider identity, never by time. `createPaymentFromWebhook()` looks for an existing row by `provider_payment_id` (so a failed invoice that later succeeds updates the same record), then for the subscription's checkout-created payment that has no intent yet. An invoice whose subscription does not exist locally throws, and the retry lands after `CheckoutCompleted` has created it.

## ENV Variables

```
STRIPE_SECRET_KEY=sk_...
STRIPE_PUBLISHABLE_KEY=pk_...
STRIPE_WEBHOOK_SECRET=whsec_...

```

Everything else the merchant decides — provider, currency, checkout redirect, abandon/expire windows — is a `BillingSettings` field edited at **Settings → Billing**. `config/config.php` holds only the module name. The provider select is fed by `PaymentGatewayManager::available()`, which lists the `createXxxDriver()` methods, and `isConfigured()` tells the admin whether that provider's keys are in the environment.

## Seeders

There is no install seeder: a real install defines its own plans in the admin panel.

Everything the module ships is demo content, run by `modules:seed --demo` through `DemoBillingDatabaseSeeder`, which calls one `Demo*Seeder` per kind of content, in dependency order:

| Seeder | What it makes |
| --- | --- |
| `DemoProductSeeder` | The Free/Pro/Team plans and their prices, with no provider IDs. A real install defines its own. |
| `DemoCustomerSeeder` | 48 users, customers and cards, signing up on a rising curve over the last twelve months. |
| `DemoSubscriptionSeeder` | A subscription per customer, one payment and invoice per billing period since signup, plus abandoned checkouts. |

Keep the `Demo` prefix on each: it is what marks the data as the demo site's rather than an install's.

`DemoBillingDatabaseSeeder` runs `CatalogPush` after the three, and it is the only step that leaves the database. The seeded plans have no provider IDs, and a plan the provider does not know is not `purchasable()` — so without this the demo's pricing page is empty. It is skipped under `runningUnitTests()` (the suite boots with the developer's own `.env`) and when the provider has no keys, and a provider that is unreachable warns rather than failing the seed. Put it here rather than inside `DemoProductSeeder`: the runner draws a task line around each seeder and swallows its output.

Two properties hold the demo data together, and a new seeder should keep both. It is **deterministic** — the customer names come from a fixed faker seed, and everything else from the customer's index — and it is **idempotent**, keyed on `*_demo_*` provider IDs, so reseeding updates rather than duplicates. `DemoSeederTest` covers the second.

Signup dates drive the whole dashboard: `DemoCustomerSeeder::SIGNUPS_PER_MONTH` is the growth curve the revenue and subscription charts draw, and `DemoSubscriptionSeeder` bills forward from each signup date to today.

Nothing in the test suites depends on the demo plans. `BillingTestHelper::createSubscriberFixtures()` creates the "Pro" product and its price itself, which is what the e2e specs assert against.

## Testing

```bash
php artisan test --testsuite=Modules --filter='^Modules\\Billing\\Tests'  # PHPUnit
npx playwright test --project="@billing*"                  # E2E
```

The provider hand-off is asserted in PHP, where the gateway is mocked; e2e cannot
reach Stripe. `BillingTestHelper::completeCheckout()` finishes a started checkout
the way the webhook would, which is what lets `checkout.flow.spec.ts` run pricing
page → checkout → subscription as one flow.

`redirect_to_gateway` is a global setting, so **exactly one spec file owns it**:
`checkout.flow.spec.ts` switches it off in `beforeAll` and back in `afterAll`,
and runs `describe.configure({ mode: 'serial' })`. The runner is `fullyParallel`
— across files as well as tests — so a second file flipping the same setting
fails both.

## Debugging Billing Issues

Before diving into code, verify the external dependencies are running:

- **Stripe CLI listener** — webhooks won't fire locally without it. Run `stripe listen --forward-to localhost/billing/webhooks/stripe` and confirm the webhook secret in `.env` (`STRIPE_WEBHOOK_SECRET`) matches the CLI output. Most "subscription not created" or "event not fired" bugs in local dev are just a missing or misconfigured listener.
- **Stripe keys** — confirm `STRIPE_SECRET_KEY` and `STRIPE_PUBLISHABLE_KEY` are set and match the environment (test vs. live). A mismatched key causes silent 401s from Stripe with no local exception.
- **Queue worker** — if listeners appear registered but notifications or role sync don't happen, check whether jobs are being queued but not processed (`php artisan queue:work`).

Uncaught page errors fail the test: the `failOnPageError` fixture in
`tests/e2e/fixtures/index.ts` is `auto`, repo-wide. A spec that provokes one on
purpose allows it by pattern, e.g.
`test.use({ allowedPageErrors: [/Network error/] })`.

## Gotchas

- `CheckoutSession` uses `uuid` as the route key (not `id`) — always resolve via UUID in URLs
- Every `provider_*_id` is namespaced by a `provider` column (the gateway driver slug). Look rows up with both, never by the provider ID alone; uniqueness is `(provider, provider_x_id)`
- `subscriptions.last_event_at` is when the last applied provider event happened. `SubscriptionUpdated`/`Deleted` skip events older than it, so a late delivery cannot roll state back
- `fulfillCheckoutIfNeeded()` only works with Stripe (calls `StripeGateway::retrieveCheckoutSession()` directly); other gateways need their own redirect-completion logic
- Webhook signature verification happens inside `StripeGateway::verifyAndParseWebhook()` before deduplication — a bad signature throws an HttpException (400), which `WebhookController` returns as a 400 response. Any other exception is a 500 so Stripe retries; do not catch and acknowledge
- Stripe API versions from 2025-03-31 keep `current_period_start/end` on the subscription **item**, not the subscription. Read periods through `BillingService::subscriptionPeriod()`, which checks both
- `Price::amount` is stored in minor currency units (cents) — always divide by 100 for display; `Currency::formatAmount()` handles this
- `SyncSubscriberRole` removes the subscriber role only when the user has no other active subscriptions — check for multiple subscriptions before assuming role removal means cancellation
- `Billable` trait must be added to the User model (same pattern as Auth's `Sociable`) — `$user->billingCustomer` will fail without it
- Products use SoftDeletes; always scope to `active()` or `displayable()` when listing plans
- **Billing history outlives the account.** `customers.user_id` is `nullOnDelete`, so deleting a user detaches the customer and its subscriptions, payments and invoices stay put. `Customer::$user_id` is nullable and every notification listener uses `$user?->notify()`; the admin shows *Account deleted* where the name would be
- `subscriptions.price_id` is `restrictOnDelete`, not cascade: products cascade to prices and the admin can force-delete a product, so cascading would take paid subscriptions with it. Archive the plan instead
- `Product` refuses a **force** delete while any of its prices has a `provider_price_id`. Prices cascade from products in the database, and a cascade does not fire the price's own model event, so without this the provider would be silently desynced
- `Price` refuses deletion while `provider_price_id` is set — the provider's prices are immutable and subscriptions bill on them, so archive there and sync
- `Product` carries an `ordered` global scope — every query comes back by `display_order`, then `id` to break ties. Callers never add their own `orderBy`; the admin table is `reorderable('display_order')`, so dragging a row there is what changes the pricing page
- `BillingSettings::$currency` is the ISO code as a **string**, because `Currency::default()` does `Currency::from()` on it. A Filament `Select` fed the enum class would hand back a `Currency` instance and fail to assign — pass an array of values instead
