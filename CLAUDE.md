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
| Listeners | The queued `SendSubscriptionCreatedNotification`, `SendSubscriptionUpdatedNotification`, `SendSubscriptionCancelledNotification`, `SendSubscriptionResumedNotification`, `SendPaymentSucceededNotification`, `SendPaymentFailedNotification` |
| Data | `CheckoutData`, `CheckoutResultData`, `WebhookData` (carries `occurredAt` for ordering), `CustomerData`, `AddressData`, `PaymentMethodData`, `PaymentMethodDetails` (Spatie Data objects) |
| Commands | `EndGracePeriodsCommand` (`billing:end-grace-periods`, hourly, suspends subscriptions whose grace window closed), `ExpireCheckoutSessionsCommand` (every 30 min, marks abandoned/expired sessions and releases the trials they hold), `SyncCatalogCommand` (`billing:sync-catalog`, daily and on the admin's *Sync* button), `PushCatalogCommand` (`billing:push-catalog`, on the admin's *Push new* button). Both in `src/Console/Commands/`, which is where internachi discovers them |
| Middleware | `RedirectToRegister` — redirects guests on checkout pages, stores intended URL |
| Filament | `BillingPlugin`, `BillingDashboard` (date range stats), `ProductResource`, `SubscriptionResource`, `CustomerResource` |
| Owner | `Contracts\BillingOwner` + `Traits\Billable` on the User model (`billingCustomer()`, entitlements, `hasPaidPlan()`); `patches/user.patch` adds both |
| Pages | `SettingsBilling`, `Checkout` |

## Frontend

Both stacks ship: `resources/js/vue/` and `resources/js/react/` hold the same four screens — `pages/Plans`, `pages/Checkout`, `pages/SettingsBilling` and `components/ProductCard`/`ProductSection` — plus a `CheckoutLayout`. Change one, change the other.

`ProductSection` drops a plan with no price for the selected interval, unless it has a `cta_url` — that is how a *Contact sales* plan shows with no price. The button reads `metadata.cta_label` in both forms.

The pricing page lists every active price, not only `purchasable()` ones, so plans show before they are pushed. Each card's button is the action the server sends (`priceActions` / `productActions`, see *Plans, Kinds and Entitlements*); `ProductCard` only renders it — `change` is a plain anchor to `billing.plan.change`, `contact` the plan's `cta_url`.

The sidebar's user menu shows the plan under the user's first name: billing shares `billing.plan` (`BillingOwner::planName()` — the subscription's plan, else a lifetime plan, else the free one) and registers `PlanName` in core's `user-subtitle` global-component slot, which falls back to the email.

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
GET   /billing/plan/change           → billing.plan.change   (throttle:10,1)
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

On return, the provider sends the buyer to `settings.billing?checkout_session={our uuid}` — our ID, not a provider template. `SettingsBillingController::show()` looks it up **scoped to the signed-in user's customer** (an unguessable ID is not permission) and calls `BillingService::fulfillCheckoutIfNeeded()` as a fallback in case the webhook hasn't fired yet. Only a `fulfillable` checkout completes: paid, or validly owing nothing (trial, 100% discount). Open, expired or unsettled delayed payments are not; the webhook completes those once they settle.

### Webhook Processing
`BillingService::handleWebhook()`:
1. Calls `gateway->verifyAndParseWebhook()` — signature verification, then the gateway translates the event into a `WebhookEventType` and its typed data (`src/Data/Webhook/*`, one class per type: `WebhookEventType::dataClass()`). Handlers read it with `$webhook->dataAs(SubscriptionStateData::class)`, which throws on a mismatch. `BillingService` never reads provider JSON
2. Deduplicates by `(provider, provider_event_id)` in `webhook_events` — one atomic `UPDATE … WHERE processed_at IS NULL` claims the event, so concurrent deliveries cannot both run. A handler that throws hands the claim back (`processed_at` null), `WebhookController` answers 500, and Stripe's retry is processed rather than skipped. This is also how out-of-order delivery recovers: an invoice for a subscription that does not exist locally yet throws and is retried.

   Retrying only helps when the missing row is still on its way, so `subscriptionForEvent()` splits the two cases: a subscription the app cannot find **under a customer it knows** throws and lets the provider retry, while one whose customer is also unknown is logged and acknowledged. Nothing here ever described that subscription — a foreign account, or a database rebuilt without it — so a 500 would have the provider retry forever.
3. Routes to private handlers via match on `WebhookEventType`:
   - `CheckoutCompleted` → locks and re-reads the session, then creates Subscription, Payment and PaymentMethod **inside the same transaction** (a retry is a no-op once the session is Completed, so nothing after commit may be the only record of what was bought; entitlements are read from those rows). Also mapped from `checkout.session.async_payment_succeeded`
   - `SubscriptionUpdated` → the gateway has already mapped the provider's status to `SubscriptionStatus` (for Stripe: active/trialing → Active, past_due → PastDue, unpaid → Suspended, canceled → Cancelled; unknown → null, which leaves the row alone), syncs period and trial dates, and runs the delinquency rules inside the lock. Applied through `applyIfCurrent()`: under a row lock, skipped if older than `last_event_at` or if the row is already Cancelled (Stripe never reactivates one). Provider calls happen before the lock
   - `SubscriptionDeleted` → marks Cancelled, fires `SubscriptionCancelled`. Like `SubscriptionUpdated`, it resolves the row through `subscriptionForEvent()` and acknowledges rather than fails when the customer is unknown
   - `PaymentSucceeded` → creates Payment, then **asks the provider** what the subscription is now if it is PastDue or Suspended (`retrieveSubscription()`), since invoice events are not ordered against each other. The read runs before the already-recorded early return, carries `state_revision` so a newer write cannot be overwritten, retries once when overtaken and then throws so the delivery is retried
   - `PaymentFailed` → creates Payment only. The subscription's own event moves it; inferring the status here would race with that
   - `SubscriptionTrialWillEnd` → mirrors trial dates and fires `TrialEnding`
   - `PaymentMethodAttached` / `PaymentMethodDetached` → keeps cards added or removed in the provider's portal in step, and clears a subscription pointing at a removed one. Attaching never makes a card the default
   - `CustomerUpdated` → the default card follows `invoice_settings.default_payment_method`, cleared when the provider clears it
   - `InvoicePaid` → creates/updates Invoice, syncs subscription period dates

### Subscription Lifecycle

Cancellation is always at period end by default. `SubscriptionController::cancel()` calls `BillingService::cancelAtPeriodEnd()`, which stops renewal at the provider and sets `cancelled_at` and `ends_at` locally. Resume undoes a scheduled cancellation — `gateway->resumeSubscription()` sets Stripe `cancel_at_period_end: false` and the timestamps are cleared locally. It cannot revive a subscription Stripe has already ended.

**Trials.** A plan carries `trial_days`; checkout asks the provider for it and mirrors `trial_start`/`trial_end` back into `trial_starts_at`/`trial_ends_at`. A trialing subscription is `Active` — the provider says so, and it grants what it sells — so there is no `Trialing` status. Each customer gets **one trial, ever**: `Customer::hasTrialed()` counts a subscription that has trialed and any Pending or Completed checkout holding `trial_days > 0`. The decision is taken under the customer's row lock and written to `checkout_sessions.trial_days` (null undecided, `0` decided against), so a retried hand-off sends what the buyer was promised and two open checkouts cannot both be trials. `billing:expire-checkout-sessions` only releases a held trial once `expireCheckoutSession()` confirms the provider's page is dead; a hand-off with no provider ID is resolved by replaying the create call under the same idempotency key, and past that 24-hour window the reservation is **kept**, not released. Whether a trial collects payment details is the `trial_requires_payment_method` setting; with it off the session carries `payment_method_collection: if_required` and asks the provider to cancel a trial that ends with no method.

**Falling behind is one episode with one deadline.** `subscriptions.grace_ends_at` is the date access stops, set once when the provider first reports `past_due` and **never extended** — a second failure continues the same episode. A suspension pulls it back to `now()`, `Suspended` is absorbing until a confirmed recovery or a cancellation, and only `active`/`trialing` clears it. `billing:end-grace-periods` (hourly) suspends what the deadline has passed, under a row lock re-checking the status, and never calls the provider: dunning is the provider's, and it may still recover the subscription.

Status transitions: `trial/Active → PastDue (deadline set) → Suspended → Active (recovered) or Cancelled`. Access is `Subscription::grantsAccess()` — Active always, PastDue only inside the window, and a PastDue row with no deadline grants nothing.

**Emails**, one per transition and best-effort: `GraceStarted` when the deadline is first set, `AccessSuspended` on the move into `Suspended`, `TrialEnding` from the provider's `trial_will_end`. A crash between the committed change and the queued job loses one; a `*_notified_at` column would narrow that window without closing it.

### Catalog Sync
The provider is the source of truth for what is sold: `CatalogSync::run()` pulls `listCatalog()` from the gateway and upserts `Product`/`Price` keyed on `(provider, provider_product_id)` / `(provider, provider_price_id)`. It writes only the provider's fields — name, active, amount, currency, interval — and on first import sets `is_visible = false`, `sku` = provider product ID, takes the provider's description and marketing features as a starting point, and appends `display_order` after the highest one already in use so an import never lands a batch of products on the same rank. After that, description, features, slug, visibility, highlight and order are the app's and are never overwritten. Archived at the provider → `is_active = false`, never deleted (subscriptions point at the rows) — but one archived *and* unknown here is skipped entirely and counted in the report's `skipped`: a plan retired before this app saw it is history, not catalogue. Prices the provider has never heard of are left alone and listed in the report's `localOnly`.

`CatalogPush::run()` is the one-off in the other direction, for plans drafted in the admin before a provider account existed: every product/price with **no** provider ID is created at the provider and gets its ID written back. `run(Product $only)` scopes it to one product — that is the *Push to Stripe* action on the product row and edit page. It never updates anything the provider already knows (provider prices are immutable — change them there and pull), so it has no conflict to resolve. Each product it creates carries `metadata.slug`; before creating one it looks for an **active** provider product with the same slug and adopts it, along with its active prices that match on currency, amount and interval, so pushing again after a database reset reconnects instead of duplicating. `listCatalog()` includes active products with no price for this lookup (a *Contact sales* plan); sync never imports one. Archive at the provider to force a fresh product. The one exception is the feature list, which `pushProductFeatures()` sends for every product on every run: the app owns a plan's marketing copy once it is here, and the provider's copy exists only so its own pricing pages can show it.

In the admin, `ProductForm` disables the provider's fields when `Product::provider_product_id` / `Price::isManagedByGateway()` is set, and hides the repeater's delete action for synced prices. A product or price with no provider ID (demo, test) stays fully editable.

### Plans, Kinds and Entitlements
A `Product` is a plan, and its `slug` is the plan's stable ID in code. Every plan has a **kind** (`PlanKind`) and **entitlements**:

| Kind | Sold with | Grants |
| --- | --- | --- |
| `Free` | never sold ($0 prices only, for display) | its entitlements, to everyone |
| `Subscription` | recurring prices | its entitlements while it grants access: Active, or PastDue inside its grace window. `trial_days` offers a free trial, once per customer |
| `Lifetime` | one-time price | its entitlements until the payment is fully refunded; `replaces_product_id` names the subscription plan it replaces |
| `OneOff` | one-time price | nothing — a service or something the app handles itself |

Entitlements are `{features: {key: true}, limits: {key: int|null}}` on the plan (`Data\Entitlements`; null is unlimited, a missing limit is 0), edited in the admin's *Plan* section. The app asks the owner: `$user->canUseFeature('exports')`, `$user->planLimit('projects')`; counting projects stays the app's job. Resolution (`Billable::entitlements()`) merges the Free plan, the current subscription's plan and every lifetime plan owned — features combine, the higher limit wins. The Free plan always merges in, so a paid plan can never lower a free limit: intended. Plans are read `withTrashed()` through `Price::plan()`, so archiving or deleting a plan never takes back what was bought. Editing a plan's entitlements changes existing customers' access immediately.

**Invariants, enforced in `Product::assertValid()` on every save** (admin, catalog import, code):
- One Free plan at most — a unique index on the generated `free_plan` column. It cannot be deleted; edit it in place.
- `replaces_product_id` only on a Lifetime plan, pointing at a Subscription plan other than itself.
- `kind`, `slug` and `replaces_product_id` are frozen once the plan is sold (`isSold()`: a Pending or Completed checkout session, a payment, or a subscription on its prices). Checkout creates the session under a `lockForUpdate` on the plan row, and `EditProduct` saves under the same lock in a transaction, so an edit cannot slip in between handoff and payment.
- Entitlement keys are snake_case; limits are whole numbers ≥ 0 or null.

**Who owns plans.** `Contracts\BillingOwner` (`billingAccount()`, `entitlements()`, `hasPaidPlan()`, `canUseFeature()`, `planLimit()`), implemented by `User` through `Billable`. Everything that decides access or purchases takes a `BillingOwner`, so a workspace can become the owner without touching the rules. The *Upgrade* menu item shows while `hasPaidPlan()` is false. There is no subscriber role: check entitlements.

**Purchases.** `PurchaseEligibility::check(?BillingOwner, Price)` is the single rule, returning a `PurchaseRefusal` or null:
- the price: inactive, not pushed, or not the kind's type (a one-time price on a Subscription plan) → `Unavailable`; the Free plan → `NotForSale`;
- a Subscription plan: already subscribed to it → `Current`; replaced by an owned Lifetime → `Included`; the current subscription is being replaced by Lifetime and running out → `AfterCurrentEnds` (wait until it ends); another current subscription → `ChangeInstead` (change it at the provider);
- a Lifetime plan already owned → `Current`; a OneOff is always allowed.

`BillingService::assertCanBuy()` throws the refusal's message; `CheckoutController::create()` calls it before a session exists and `processCheckout()` again for the guest who signed in part-way (the session-reuse return runs first, so a checkout already paid at the provider still completes). It runs before payment, so two checkouts opened at once can both be paid; Stripe's *Limit customers to one subscription* closes that, listed in the README as optional.

**Pricing buttons come from the server.** `PlanActions::for()` sends `priceActions` (per displayed price) and `productActions` (per plan shown without a price). `buy` appears only where eligibility allows; the rest label a refusal — `change`, `later`, `included`, `current`, `unavailable` — plus `contact` for a `cta_url` and, for the Free plan, `signup` (guest), `current` (nothing paid) or `included` (paid plan). The cards only render what they are sent.

**Lifetime replacing a subscription.** After a Lifetime checkout commits, `endSubscriptionReplacedByLifetime()` cancels the current subscription at period end — only if its plan is the one the Lifetime replaces. It runs on every delivery, since a failed provider call is retried and the retry finds the session already completed. Until the provider accepts the cancellation the subscription shows as renewing; once `cancelled_at` is set the panel says it ends, replaced by lifetime. The app freezes it: `billing.plan.change` 404s and resume is refused (`PurchaseEligibility::isReplacedByLifetime()`). That is app policy — the provider stays the source of truth, and a customer who resumes it in the provider's portal keeps an ordinary subscription.

**Refunds.** `charge.refunded` with `refunded: true` marks the payment Refunded, which ends a lifetime plan; a partial refund only updates `amount_refunded`. A refund that arrives before its checkout has recorded the payment throws for a known customer, so the provider retries it; one with no `payment_intent`, or for an unknown customer, is acknowledged.

**Plan changes happen at the provider.** `GET /billing/plan/change` (`billing.plan.change`) sends the subscriber to `PaymentGatewayInterface::getPlanChangeUrl()` — Stripe's billing portal opened on its plan picker. Only the user's own current subscription is used; nothing in the request names one. The change comes back as `customer.subscription.updated`, and `onSubscriptionUpdated()` moves the row to the local price with that provider ID. A price not pulled yet is logged and skipped: the daily catalog sync and the next event put it right. Which plans the portal offers, and whether downgrades wait for the period end, is the portal's configuration in the Stripe dashboard.

### Gateway Driver Pattern
`PaymentGatewayManager` extends Laravel's `Manager`. The default driver is `billing.default_gateway` config. Adding a new gateway means implementing `PaymentGatewayInterface` and adding a `createXxxDriver()` method. The gateway talks to the provider and returns identifiers; `BillingService` owns every local row (`createCustomer()` returns the provider's customer ID, the service creates the `Customer`).

The **data contract is provider-neutral**: incoming webhooks, `retrieveSubscription()` and `retrieveCheckoutSession()` all come back as the module's typed data, and `BillingService` holds no provider class and reads no provider JSON. Stripe's side of that is `StripeEventMapper` — pure, array in, data out — which is where every Stripe quirk lives (status names, the period on the item since API 2025-03-31, how a cancellation is spelled, invoice line spans). A new provider writes its own mapper. `…Reference` fields are opaque: a gateway must resolve every reference it emits through `resolvePaymentMethod()`, whatever kind of ID it is. `Optional` in the data means "not mentioned, leave it"; `null` means "cleared".

It is **not** a drop-in for any provider yet: optional operations (portal, plan change) have no capability flags, payment identity across checkout/payment/refund is Stripe-shaped (an invoice ID stands in when there is no payment intent), and running two providers at once is sc-788. `NeutralGatewayTest` is the proof of the contract — a fake provider that speaks only the module's data.

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
| `DemoProductSeeder` | Five plans for a made-up developer tool, each with a kind and entitlements — Free, Pro (highlighted), Team, Lifetime (replaces Pro) and Enterprise (no price, `cta_url` to sales) — chosen so the pricing page shows every option it reads. No provider IDs. A real install defines its own. |
| `DemoCustomerSeeder` | 48 users, customers and cards, signing up on a rising curve over the last twelve months. |
| `DemoSubscriptionSeeder` | A subscription per customer, one payment and invoice per billing period since signup, plus abandoned checkouts. |

Keep the `Demo` prefix on each: it is what marks the data as the demo site's rather than an install's.

`DemoBillingDatabaseSeeder` runs `CatalogPush` after the three, and it is the only step that leaves the database. The seeded plans have no provider IDs, and a plan the provider does not know is not `purchasable()` — so without this every paid plan on the demo's pricing page says *Not available*. It is skipped under `runningUnitTests()` (the suite boots with the developer's own `.env`) and when the provider has no keys, and a provider that is unreachable warns rather than failing the seed. Put it here rather than inside `DemoProductSeeder`: the runner draws a task line around each seeder and swallows its output.

Two properties hold the demo data together, and a new seeder should keep both. It is **deterministic** — the customer names come from a fixed faker seed, and everything else from the customer's index — and it is **idempotent**, keyed on slugs, users and `*_demo_*` IDs, so reseeding updates rather than duplicates. Customers carry no provider ID: checkout creates a real one the first time a demo account buys. `DemoSeederTest` covers the second.

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

## Errors

Billing failures are `src/Exceptions/*`, all extending `BillingException`. Each has a stable `id()` that every report carries as `billing_error_id`, and a `context()` built from named fields only — never provider arrays, request bodies, URLs or payment details. Business refusals stay Laravel's own (`ValidationException`, `AuthorizationException`, `abort(404/410/403)`).

| `billing_error_id` | Thrown by | Handled |
| --- | --- | --- |
| `billing.gateway_operation_failed` | `StripeGateway::call()`, around every SDK call — **only** for Stripe's `ApiErrorException`; SDK misuse and `TypeError` pass through untranslated | Interactive: reported once, safe toast, nothing changed. Checkout hand-off: reported, `Billing::Checkout` rendered with `handoffFailed` and a **Try again** that `POST`s `billing.checkout.retry` for the **same** session (same stored request, same idempotency key). Return fulfilment and post-checkout period sync: reported, the webhook completes it. Webhook: reported, 500 |
| `billing.provider_error` | never thrown; the `previous` of the above | Keeps SDK class, provider code, HTTP status, request ID and a **redacted** message (keys, card-like numbers, emails) — the raw SDK exception is not chained, because the reporter writes every previous message |
| `billing.invalid_webhook_signature` | `verifyAndParseWebhook()` | Empty 400, warning log, not reported |
| `billing.invalid_webhook_data` | `WebhookData::dataAs()` | Reported, empty 500 — a gateway defect |
| `billing.webhook_dependency_not_ready` | a known customer's subscription or payment not here yet | Empty 500, info log, **not** reported — the resend recovers it; context names the `provider_event_id` |
| `billing.subscription_reconciliation_conflict` | `reconcileDelinquency()` overtaken on every read | Reported, empty 500, claim released for the resend |

Unsupported events are acknowledged (200). Events for unknown customers are acknowledged, as before.

**Checkout expiry is explicit.** `expireCheckoutSession()` returns `CheckoutExpiry::Expired` or `Completed`, or throws. Only a read-back `expired` releases a trial; `resource_missing`, auth errors and outages are *unknown* and keep it held.

**Commands.** `billing:expire-checkout-sessions` prints expired / abandoned / kept / unresolved and exits 1 when anything is unresolved (each reported with its checkout). *Kept* means the buyer completed it — not a failure. A crashed hand-off past the 24-hour replay window is logged, not counted, so it cannot fail every run forever; that is sc-787's reconciliation. `billing:end-grace-periods` isolates rows, reports a failed one, exits 1 when any failed.

**Debugging.** Search logs for `billing_error_id`; `provider_request_id` finds the call in Stripe's dashboard (Developers → Logs). A burst of `webhook_dependency_not_ready` at info level is normal event reordering; one that never clears means the creating event is not subscribed to (check the `stripe listen --events` list).

## Debugging Billing Issues

Before diving into code, verify the external dependencies are running:

- **Stripe CLI listener** — webhooks won't fire locally without it. Run `task billing:webhook:stripe:listen` (it passes the `--events` list the CLI now requires) and confirm the webhook secret in `.env` (`STRIPE_WEBHOOK_SECRET`) matches the CLI output. Most "subscription not created" or "event not fired" bugs in local dev are just a missing or misconfigured listener.
- **Stripe keys** — confirm `STRIPE_SECRET_KEY` and `STRIPE_PUBLISHABLE_KEY` are set and match the environment (test vs. live). A mismatched key causes silent 401s from Stripe with no local exception.
- **Queue worker** — if listeners appear registered but notifications don't go out, check whether jobs are being queued but not processed (`php artisan queue:work`).

Uncaught page errors fail the test: the `failOnPageError` fixture in
`tests/e2e/fixtures/index.ts` is `auto`, repo-wide. A spec that provokes one on
purpose allows it by pattern, e.g.
`test.use({ allowedPageErrors: [/Network error/] })`.

## Gotchas

- `CheckoutSession` uses `uuid` as the route key (not `id`) — always resolve via UUID in URLs
- Every `provider_*_id` is namespaced by a `provider` column (the gateway driver slug). Look rows up with both, never by the provider ID alone; uniqueness is `(provider, provider_x_id)`
- `subscriptions.last_event_at` is when the last applied provider event happened. `SubscriptionUpdated`/`Deleted` skip events older than it, so a late delivery cannot roll state back
- Webhook signature verification happens inside `StripeGateway::verifyAndParseWebhook()` before deduplication — a bad signature throws `InvalidWebhookSignature`, which `WebhookController` answers with an empty 400. Any other exception is an empty 500 so Stripe retries; do not catch and acknowledge (see *Errors*)
- Stripe API versions from 2025-03-31 keep `current_period_start/end` on the subscription **item**, not the subscription. `StripeEventMapper::subscription()` checks both; nothing else reads periods from Stripe
- Webhook tests build deliveries with `Tests\Support\StripeWebhook::make()`, which runs Stripe-shaped fixtures through the real mapper — mapper + service integration, not Stripe end to end. It defaults a completed checkout to `payment_status: paid`, as Stripe always sends one
- `Price::amount` is stored in minor currency units (cents) — always divide by 100 for display; `Currency::formatAmount()` handles this
- The User model needs `implements BillingOwner` and `use Billable` (`patches/user.patch`, same pattern as Auth's `Sociable`) — entitlement checks and `$user->billingCustomer` fail without them
- Products use SoftDeletes; always scope to `active()` or `displayable()` when listing plans
- **Billing history outlives the account.** `customers.user_id` is `nullOnDelete`, so deleting a user detaches the customer and its subscriptions, payments and invoices stay put. `Customer::$user_id` is nullable and every notification listener uses `$user?->notify()`; the admin shows *Account deleted* where the name would be
- `subscriptions.price_id` is `restrictOnDelete`, not cascade: products cascade to prices and the admin can force-delete a product, so cascading would take paid subscriptions with it. Archive the plan instead
- `Product` refuses a **force** delete while any of its prices has a `provider_price_id`. Prices cascade from products in the database, and a cascade does not fire the price's own model event, so without this the provider would be silently desynced
- `Price` refuses deletion while `provider_price_id` is set — the provider's prices are immutable and subscriptions bill on them, so archive there and sync
- **`currentSubscription()` is membership, not access.** It returns what the customer holds — Active, PastDue or Suspended — so a suspended customer still sees their plan, reaches the portal and cannot buy a second one. Access is `Subscription::grantsAccess()`, asked in one place: `Billable`'s entitlements, plan name and `hasPaidPlan()`
- `SubscriptionStatus::Suspended` (Stripe's `unpaid`, translated only in `StripeEventMapper`) is recoverable; `Cancelled` is absorbing in `applyIfCurrent()`. Never let the sweeper write `Cancelled`, or a later recovery could never apply
- A grace deadline never extends within an episode, though a suspension may shorten it. An unresolved trial reservation is held rather than released: handing out a second trial is worse than withholding one
- `Product` carries an `ordered` global scope — every query comes back by `display_order`, then `id` to break ties. Callers never add their own `orderBy`; the admin table is `reorderable('display_order')`, so dragging a row there is what changes the pricing page
- `BillingSettings::$currency` is the ISO code as a **string**, because `Currency::default()` does `Currency::from()` on it. A Filament `Select` fed the enum class would hand back a `Currency` instance and fail to assign — pass an array of values instead
