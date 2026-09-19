<?php

namespace Modules\Billing\Tests\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\BillingScheme;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PaymentGatewayManager;

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
