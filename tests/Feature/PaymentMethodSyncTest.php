<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Data\PaymentMethodData;
use Modules\Billing\Data\PaymentMethodDetails;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\PaymentMethodType;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Tests\Support\StripeWebhook;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * A card added or removed in the provider's portal has to reach the app: it is
 * what the billing panel reads before nudging a trialing customer for one.
 */
class PaymentMethodSyncTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    private Customer $customer;

    /** @var list<WebhookData> */
    private array $deliveries = [];

    private int $delivered = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(StripeGateway::class);
        $this->gateway->method('verifyAndParseWebhook')->willReturnCallback(function (): WebhookData {
            return array_shift($this->deliveries);
        });
        $this->gateway->method('resolvePaymentMethod')->willReturn(new PaymentMethodData(
            providerPaymentMethodId: 'pm_portal',
            type: PaymentMethodType::Card,
            details: new PaymentMethodDetails(brand: 'visa', last4: '4242', expMonth: 12, expYear: 2030),
        ));

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->billing = app(BillingService::class);
        $this->customer = Customer::factory()->create(['provider' => 'stripe', 'provider_customer_id' => 'cus_portal']);
    }

    /** @param  array<string, mixed>  $payload */
    private function deliver(WebhookEventType $type, array $payload): void
    {
        $this->deliveries[] = StripeWebhook::make(
            type: $type,
            provider: 'stripe',
            providerEventId: 'evt_'.++$this->delivered,
            payload: $payload,
        );

        $this->billing->handleWebhook('stripe', request());
    }

    public function test_a_card_added_in_the_portal_arrives(): void
    {
        $this->deliver(WebhookEventType::PaymentMethodAttached, ['id' => 'pm_portal', 'customer' => 'cus_portal']);

        $this->assertDatabaseHas('payment_methods', [
            'customer_id' => $this->customer->id,
            'provider_payment_method_id' => 'pm_portal',
        ]);
    }

    public function test_a_card_removed_in_the_portal_goes(): void
    {
        $method = PaymentMethod::factory()->create([
            'customer_id' => $this->customer->id,
            'provider' => 'stripe',
            'provider_payment_method_id' => 'pm_portal',
        ]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'payment_method_id' => $method->id,
        ]);

        $this->deliver(WebhookEventType::PaymentMethodDetached, ['id' => 'pm_portal', 'customer' => 'cus_portal']);

        $this->assertDatabaseMissing('payment_methods', ['id' => $method->id]);
        $this->assertNull($subscription->fresh()->payment_method_id);
    }

    public function test_a_card_this_app_never_saw_is_ignored(): void
    {
        $this->deliver(WebhookEventType::PaymentMethodDetached, ['id' => 'pm_unknown', 'customer' => 'cus_portal']);

        $this->assertDatabaseCount('payment_methods', 0);
    }

    public function test_an_unknown_customer_is_ignored(): void
    {
        $this->deliver(WebhookEventType::PaymentMethodAttached, ['id' => 'pm_portal', 'customer' => 'cus_someone_else']);

        $this->assertDatabaseCount('payment_methods', 0);
    }

    /** Without an ID the event names nothing, and must not match every row missing one. */
    public function test_an_event_without_an_id_changes_nothing(): void
    {
        PaymentMethod::factory()->create(['customer_id' => $this->customer->id, 'provider' => 'stripe', 'provider_payment_method_id' => null]);

        $this->deliver(WebhookEventType::PaymentMethodAttached, ['customer' => 'cus_portal']);
        $this->deliver(WebhookEventType::PaymentMethodDetached, ['customer' => 'cus_portal']);

        $this->assertDatabaseCount('payment_methods', 1);
    }

    /** Attaching a card does not make it the one invoices are charged to. */
    public function test_an_attached_card_is_not_made_the_default(): void
    {
        $default = PaymentMethod::factory()->create(['customer_id' => $this->customer->id, 'provider' => 'stripe', 'provider_payment_method_id' => 'pm_default', 'is_default' => true]);

        $this->deliver(WebhookEventType::PaymentMethodAttached, ['id' => 'pm_portal', 'customer' => 'cus_portal']);

        $this->assertFalse(PaymentMethod::where('provider_payment_method_id', 'pm_portal')->value('is_default'));
        $this->assertTrue($default->fresh()->is_default);
    }

    /**
     * Stripe sends the same card in several events at once. Another webhook
     * saving it between this one's check and its insert is not a failure.
     */
    public function test_a_card_saved_by_a_concurrent_webhook_is_reused(): void
    {
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if (! $raced && str_starts_with($query->sql, 'select * from "payment_methods"')) {
                $raced = true;
                PaymentMethod::factory()->create([
                    'customer_id' => $this->customer->id,
                    'provider' => 'stripe',
                    'provider_payment_method_id' => 'pm_portal',
                    'is_default' => false,
                ]);
            }
        });

        $this->deliver(WebhookEventType::CustomerUpdated, ['id' => 'cus_portal', 'invoice_settings' => ['default_payment_method' => 'pm_portal']]);

        $this->assertDatabaseCount('payment_methods', 1);
        $this->assertTrue(PaymentMethod::where('provider_payment_method_id', 'pm_portal')->value('is_default'));
    }

    public function test_the_customers_default_follows_the_provider(): void
    {
        $this->deliver(WebhookEventType::PaymentMethodAttached, ['id' => 'pm_portal', 'customer' => 'cus_portal']);

        $this->deliver(WebhookEventType::CustomerUpdated, ['id' => 'cus_portal', 'invoice_settings' => ['default_payment_method' => 'pm_portal']]);

        $this->assertTrue(PaymentMethod::where('provider_payment_method_id', 'pm_portal')->value('is_default'));
    }

    public function test_a_default_cleared_at_the_provider_is_cleared_here(): void
    {
        PaymentMethod::factory()->create(['customer_id' => $this->customer->id, 'provider' => 'stripe', 'provider_payment_method_id' => 'pm_default', 'is_default' => true]);

        $this->deliver(WebhookEventType::CustomerUpdated, ['id' => 'cus_portal', 'invoice_settings' => ['default_payment_method' => null]]);

        $this->assertSame(0, PaymentMethod::where('is_default', true)->count());
    }

    public function test_a_subscription_whose_default_is_removed_loses_it(): void
    {
        $method = PaymentMethod::factory()->create(['customer_id' => $this->customer->id, 'provider' => 'stripe', 'provider_payment_method_id' => 'pm_sub']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_portal',
            'payment_method_id' => $method->id,
        ]);

        $this->deliver(WebhookEventType::SubscriptionUpdated, ['id' => 'sub_portal', 'customer' => 'cus_portal', 'status' => 'active', 'default_payment_method' => null]);

        $this->assertNull($subscription->fresh()->payment_method_id);
    }

    /** Attaching a card is not paying with it: only a default makes the prompt go. */
    public function test_the_prompt_follows_the_default_not_the_attachment(): void
    {
        $subscription = Subscription::factory()->create(['customer_id' => $this->customer->id, 'payment_method_id' => null]);

        $this->deliver(WebhookEventType::PaymentMethodAttached, ['id' => 'pm_portal', 'customer' => 'cus_portal']);
        $this->assertFalse($subscription->fresh()->hasPaymentMethod(), 'Attached, not default');

        $this->deliver(WebhookEventType::CustomerUpdated, ['id' => 'cus_portal', 'invoice_settings' => ['default_payment_method' => 'pm_portal']]);
        $this->assertTrue($subscription->fresh()->hasPaymentMethod(), 'Made the default');

        $this->deliver(WebhookEventType::CustomerUpdated, ['id' => 'cus_portal', 'invoice_settings' => ['default_payment_method' => null]]);
        $this->assertFalse($subscription->fresh()->hasPaymentMethod(), 'Default cleared');
    }
}
