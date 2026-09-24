<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Data\Webhook\RefundData;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Exceptions\InvalidWebhookData;
use Modules\Billing\Exceptions\InvalidWebhookSignature;
use Modules\Billing\Exceptions\WebhookDependencyNotReady;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * What the provider hears back. A 500 is how it is told to try again, so it
 * must mean exactly that; a 400 means "never send this again".
 */
class WebhookErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    /** @var list<WebhookData|\Throwable> */
    private array $deliveries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Exceptions::fake();

        $this->gateway = $this->createMock(StripeGateway::class);
        $this->gateway->method('verifyAndParseWebhook')->willReturnCallback(function (): WebhookData {
            $next = array_shift($this->deliveries);

            return $next instanceof \Throwable ? throw $next : $next;
        });

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
    }

    private function deliver(WebhookData|\Throwable $delivery): TestResponse
    {
        $this->deliveries[] = $delivery;

        return $this->postJson(route('billing.webhooks', 'stripe'));
    }

    private function subscriptionUpdate(string $eventId = 'evt_1'): WebhookData
    {
        return new WebhookData(WebhookEventType::SubscriptionUpdated, 'stripe', $eventId,
            new SubscriptionStateData('sub_late', 'cus_known', SubscriptionStatus::Active));
    }

    public function test_a_bad_signature_is_refused_with_nothing_said(): void
    {
        $response = $this->deliver(new InvalidWebhookSignature('stripe'));

        $response->assertStatus(400);
        $this->assertSame('', $response->getContent());
        Exceptions::assertNotReported(InvalidWebhookSignature::class);
    }

    public function test_an_event_the_module_does_not_handle_is_acknowledged(): void
    {
        $this->deliver(new WebhookData(null, 'stripe', 'evt_other'))->assertOk();

        Exceptions::assertNothingReported();
    }

    /** Arrived before the subscription it describes: fail, let the provider resend, and do not alarm anyone. */
    public function test_an_event_ahead_of_its_subscription_is_retried_until_it_lands(): void
    {
        $customer = Customer::factory()->create(['provider' => 'stripe', 'provider_customer_id' => 'cus_known']);

        $this->deliver($this->subscriptionUpdate())->assertStatus(500);
        $this->assertDatabaseHas('webhook_events', ['provider_event_id' => 'evt_1', 'processed_at' => null]);
        Exceptions::assertNotReported(WebhookDependencyNotReady::class);

        Subscription::factory()->create(['customer_id' => $customer->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_late']);

        $this->deliver($this->subscriptionUpdate())->assertOk();
        $this->assertDatabaseMissing('webhook_events', ['provider_event_id' => 'evt_1', 'processed_at' => null]);
    }

    /** A gateway sending the wrong shape is a defect: reported, and failed so nothing is lost. */
    public function test_incompatible_event_data_fails_and_is_reported_once(): void
    {
        $response = $this->deliver(new WebhookData(WebhookEventType::SubscriptionUpdated, 'stripe', 'evt_bad', new RefundData(null, 'pi_1', 1, true)));

        $response->assertStatus(500);
        $this->assertSame('', $response->getContent());
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(InvalidWebhookData::class);
        $this->assertDatabaseHas('webhook_events', ['provider_event_id' => 'evt_bad', 'processed_at' => null]);
    }

    public function test_a_dependency_wait_names_the_delivery(): void
    {
        Customer::factory()->create(['provider' => 'stripe', 'provider_customer_id' => 'cus_known']);

        try {
            app(BillingService::class)->handleWebhook('stripe', tap(request(), fn () => $this->deliveries[] = $this->subscriptionUpdate('evt_named')));
            $this->fail('No exception.');
        } catch (WebhookDependencyNotReady $e) {
            $this->assertSame('evt_named', $e->context()['provider_event_id']);
            $this->assertSame('billing.webhook_dependency_not_ready', $e->context()['billing_error_id']);
        }
    }
}
