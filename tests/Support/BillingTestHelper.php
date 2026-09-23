<?php

namespace Modules\Billing\Tests\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\BillingScheme;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PaymentGatewayManager;
use Tests\Support\TestFixtures;

class BillingTestHelper
{
    public static function createSubscriberFixtures(): void
    {
        if (! config('app.debug')) {
            return;
        }

        // Owned by the suite rather than taken from the demo seeder: `modules:seed`
        // installs no products, and e2e expectations name this plan.
        $product = Product::firstOrCreate(
            ['slug' => 'pro'],
            ['sku' => 'pro', 'name' => 'Pro', 'description' => 'End-to-end test plan', 'display_order' => 1, 'is_visible' => true, 'is_highlighted' => true, 'is_active' => true],
        );

        $price = Price::firstOrCreate(
            ['provider_price_id' => 'price_e2e_pro_monthly'],
            [
                'product_id' => $product->id,
                'currency' => Currency::default(),
                'amount' => 2900,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => 'month',
                'interval_count' => 1,
                'is_active' => true,
            ],
        );

        // --- Active subscriber ---
        $subscriber = User::firstOrCreate(
            ['email' => 'subscriber@example.com'],
            ['name' => 'Subscriber User', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        $subscriber->assignRole('user');

        $subscriberCustomer = Customer::firstOrCreate(
            ['user_id' => $subscriber->id],
            ['email' => $subscriber->email, 'name' => $subscriber->name, 'provider' => 'stripe', 'provider_customer_id' => 'cus_test_subscriber'],
        );

        CheckoutSession::firstOrCreate(
            ['provider' => 'stripe', 'provider_session_id' => 'cs_test_active'],
            ['price_id' => $price->id, 'customer_id' => $subscriberCustomer->id, 'status' => CheckoutSessionStatus::Pending],
        );

        self::handleFakeWebhook(WebhookEventType::CheckoutCompleted, [
            'id' => 'cs_test_active',
            'subscription' => 'sub_test_active',
            'currency' => 'eur',
            'amount_total' => 2900,
        ], 'evt_fixture_active');

        // --- Cancelled subscriber (pending cancellation) ---
        $cancelled = User::firstOrCreate(
            ['email' => 'cancelled@example.com'],
            ['name' => 'Cancelled User', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        $cancelled->assignRole('user');

        $cancelledCustomer = Customer::firstOrCreate(
            ['user_id' => $cancelled->id],
            ['email' => $cancelled->email, 'name' => $cancelled->name, 'provider' => 'stripe', 'provider_customer_id' => 'cus_test_cancelled'],
        );

        CheckoutSession::firstOrCreate(
            ['provider' => 'stripe', 'provider_session_id' => 'cs_test_cancelled'],
            ['price_id' => $price->id, 'customer_id' => $cancelledCustomer->id, 'status' => CheckoutSessionStatus::Pending],
        );

        self::handleFakeWebhook(WebhookEventType::CheckoutCompleted, [
            'id' => 'cs_test_cancelled',
            'subscription' => 'sub_test_cancelled',
            'currency' => 'eur',
            'amount_total' => 2900,
        ], 'evt_fixture_cancelled');

        // Simulate pending cancellation via subscription.updated webhook
        self::handleFakeWebhook(WebhookEventType::SubscriptionUpdated, [
            'id' => 'sub_test_cancelled',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'current_period_end' => now()->addDays(30)->timestamp,
            'cancel_at' => now()->addDays(30)->timestamp,
        ], 'evt_fixture_cancelled_update');
    }

    /**
     * Extra plans the pricing page is asserted against.
     *
     * Kept apart from the subscriber fixtures because the specs that use them
     * care about what is *listed*, not what anybody is paying for.
     */
    public static function createPricingFixtures(): void
    {
        if (! config('app.debug')) {
            return;
        }

        $starter = Product::firstOrCreate(
            ['slug' => 'starter'],
            ['sku' => 'starter', 'name' => 'Starter', 'display_order' => 0, 'is_visible' => true, 'is_active' => true],
        );

        Price::firstOrCreate(
            ['provider_price_id' => 'price_e2e_starter_monthly'],
            [
                'product_id' => $starter->id,
                'currency' => Currency::default(),
                'amount' => 900,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => 'month',
                'interval_count' => 1,
                'is_active' => true,
            ],
        );

        // Imported from the provider but not yet reviewed: must never be offered.
        Product::firstOrCreate(
            ['slug' => 'unreviewed'],
            ['sku' => 'unreviewed', 'name' => 'Unreviewed', 'display_order' => 9, 'is_visible' => false, 'is_active' => true],
        );
    }

    /**
     * One subscriber per state the billing panel draws differently: on a trial
     * with no card, inside a grace window, and suspended. Plus a plan with a
     * trial for the pricing page. Rows are written straight in: the webhook
     * rules that produce them are asserted in PHP.
     */
    public static function createLifecycleFixtures(): void
    {
        if (! config('app.debug')) {
            return;
        }

        $trialPlan = Product::firstOrCreate(
            ['slug' => 'trial'],
            ['sku' => 'trial', 'name' => 'Trial', 'display_order' => 5, 'is_visible' => true, 'is_active' => true, 'trial_days' => 14],
        );

        Price::firstOrCreate(
            ['provider_price_id' => 'price_e2e_trial_monthly'],
            [
                'product_id' => $trialPlan->id,
                'currency' => Currency::default(),
                'amount' => 1900,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => 'month',
                'interval_count' => 1,
                'is_active' => true,
            ],
        );

        $pro = Price::where('provider_price_id', 'price_e2e_pro_monthly')->firstOrFail();

        $states = [
            'trialing' => ['status' => SubscriptionStatus::Active, 'trial_starts_at' => now(), 'trial_ends_at' => now()->addDays(10)],
            'pastdue' => ['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->addDays(3)],
            'suspended' => ['status' => SubscriptionStatus::Suspended, 'grace_ends_at' => now()->subDay()],
        ];

        foreach ($states as $state => $attributes) {
            $user = User::firstOrCreate(
                ['email' => "{$state}@example.com"],
                ['name' => ucfirst($state).' User', 'password' => Hash::make(TestFixtures::SHARED_PASSWORD), 'email_verified_at' => now()],
            );

            $user->assignRole('user');

            $customer = Customer::firstOrCreate(
                ['user_id' => $user->id],
                ['email' => $user->email, 'name' => $user->name, 'provider' => 'stripe', 'provider_customer_id' => "cus_test_{$state}"],
            );

            Subscription::firstOrCreate(
                ['provider' => 'stripe', 'provider_subscription_id' => "sub_test_{$state}"],
                [
                    'customer_id' => $customer->id,
                    'price_id' => $pro->id,
                    'current_period_starts_at' => now()->subDays(20),
                    'current_period_ends_at' => now()->addDays(10),
                    ...$attributes,
                ],
            );
        }
    }

    /**
     * @return array<string, array{email: string, password: string}>
     */
    public static function lifecycleCredentials(): array
    {
        return collect(['trialing', 'pastdue', 'suspended'])
            ->mapWithKeys(fn (string $state) => [$state => ['email' => "{$state}@example.com", 'password' => TestFixtures::SHARED_PASSWORD]])
            ->all();
    }

    /**
     * A fresh subscriber whose grace window closed an hour ago and whom the
     * sweeper has not reached yet. Fresh per call, so a spec can run the sweeper
     * on it without touching anybody else's rows.
     *
     * @return array{email: string, password: string}
     */
    public static function subscriberPastTheirDeadline(): array
    {
        $user = User::factory()->create(['password' => Hash::make(TestFixtures::SHARED_PASSWORD), 'email_verified_at' => now()]);
        $user->assignRole('user');

        $customer = Customer::create(['user_id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'provider' => 'stripe', 'provider_customer_id' => 'cus_e2e_'.$user->id]);

        Subscription::create([
            'customer_id' => $customer->id,
            'price_id' => Price::where('provider_price_id', 'price_e2e_pro_monthly')->value('id'),
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_e2e_'.$user->id,
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->subHour(),
            'current_period_starts_at' => now()->subDays(20),
            'current_period_ends_at' => now()->addDays(10),
        ]);

        return ['email' => $user->email, 'password' => TestFixtures::SHARED_PASSWORD];
    }

    /** The provider reporting this user's subscription paid up again. */
    public static function recover(string $email): void
    {
        $user = User::where('email', $email)->firstOrFail();

        self::handleFakeWebhook(WebhookEventType::SubscriptionUpdated, [
            'id' => 'sub_e2e_'.$user->id,
            'status' => 'active',
        ], 'evt_e2e_recover_'.$user->id);
    }

    /**
     * Finish the checkout this user just started, the way the provider's webhook
     * would. The hand-off itself needs Stripe; everything after it does not.
     */
    public static function completeCheckout(string $email): void
    {
        if (! config('app.debug')) {
            return;
        }

        $user = User::where('email', $email)->firstOrFail();

        $customer = Customer::firstOrCreate(
            ['user_id' => $user->id],
            ['email' => $user->email, 'name' => $user->name, 'provider' => 'stripe', 'provider_customer_id' => 'cus_e2e_'.$user->id],
        );

        $session = CheckoutSession::where('status', CheckoutSessionStatus::Pending)
            ->where(fn ($query) => $query->whereNull('customer_id')->orWhere('customer_id', $customer->id))
            ->latest('id')
            ->firstOrFail();

        $session->update([
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'provider_session_id' => 'cs_e2e_'.$session->id,
        ]);

        self::handleFakeWebhook(WebhookEventType::CheckoutCompleted, [
            'id' => 'cs_e2e_'.$session->id,
            'subscription' => 'sub_e2e_'.$session->id,
            'currency' => 'eur',
            'amount_total' => 2900,
        ], 'evt_e2e_'.$session->id);

        // A plan with a trial starts trialing, which Stripe reports on the subscription.
        $trialDays = $session->price->product->trial_days;

        if ($trialDays) {
            self::handleFakeWebhook(WebhookEventType::SubscriptionUpdated, [
                'id' => 'sub_e2e_'.$session->id,
                'status' => 'trialing',
                'trial_start' => now()->timestamp,
                'trial_end' => now()->addDays($trialDays)->timestamp,
            ], 'evt_e2e_trial_'.$session->id);
        }
    }

    private static function handleFakeWebhook(WebhookEventType $type, array $payload, string $eventId): void
    {
        $webhookData = new WebhookData(
            type: $type,
            provider: 'stripe',
            providerEventId: $eventId,
            payload: $payload,
        );

        $mockGateway = new class($webhookData)
        {
            public function __construct(private WebhookData $data) {}

            public function verifyAndParseWebhook(Request $request): WebhookData
            {
                return $this->data;
            }
        };

        app()->bind(PaymentGatewayManager::class, fn () => new class($mockGateway) extends PaymentGatewayManager
        {
            public function __construct(private $driver)
            {
                parent::__construct(app());
            }

            public function driver($name = null)
            {
                return $this->driver;
            }
        });

        app(BillingService::class)->handleWebhook('stripe', new Request);

        app()->forgetInstance(BillingService::class);
        app()->forgetInstance(PaymentGatewayManager::class);
        app()->offsetUnset(PaymentGatewayManager::class);
    }
}
