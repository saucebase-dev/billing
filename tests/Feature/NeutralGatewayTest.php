<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Data\CustomerData;
use Modules\Billing\Data\PaymentMethodData;
use Modules\Billing\Data\Webhook\CheckoutSessionData;
use Modules\Billing\Data\Webhook\InvoicePaymentData;
use Modules\Billing\Data\Webhook\RefundData;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\Webhook\WebhookEventData;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\CheckoutExpiry;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\PaymentMethodType;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Exceptions\GatewayOperationFailed;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PaymentGatewayManager;
use Saucebase\Core\Settings\SettingsSection;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

/**
 * A provider that is not Stripe, speaking only the module's data. If billing
 * works end to end here, a new provider is a gateway class and nothing else —
 * no Stripe-shaped JSON, no Stripe class, and no step that quietly does nothing
 * because the gateway is not Stripe.
 */
class NeutralGatewayTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    private BillingService $billing;

    private User $user;

    private Customer $customer;

    private Price $price;

    private int $delivered = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('fake');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->billing = app(BillingService::class);
        $this->user = $this->createUser();
        $this->customer = Customer::factory()->for($this->user, 'owner')->create(['provider' => 'fake', 'provider_customer_id' => 'fcus_1']);
        $this->price = Price::factory()->create(['product_id' => Product::factory()->create()->id]);
    }

    private function deliver(WebhookEventType $type, WebhookEventData $data): void
    {
        $this->gateway->next = new WebhookData($type, 'fake', 'fevt_'.++$this->delivered, $data);

        $this->billing->handleWebhook('fake', request());
    }

    private function openCheckout(string $sessionId): CheckoutSession
    {
        return CheckoutSession::create([
            'customer_id' => $this->customer->id,
            'price_id' => $this->price->id,
            'status' => CheckoutSessionStatus::Pending,
            'provider' => 'fake',
            'provider_session_id' => $sessionId,
        ]);
    }

    private function completed(string $sessionId, bool $fulfillable = true): CheckoutSessionData
    {
        return new CheckoutSessionData($sessionId, $fulfillable, 'fsub_1', null, 'fsub_1', Currency::EUR, 2900);
    }

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => $this->price->id,
            'provider' => 'fake',
            'provider_subscription_id' => 'fsub_1',
            ...$attributes,
        ]);
    }

    public function test_a_completed_checkout_stores_the_card_and_the_period(): void
    {
        $this->openCheckout('fcs_1');
        $this->gateway->cards['fsub_1'] = 'fpm_1';
        $this->gateway->remote = new SubscriptionStateData('fsub_1', 'fcus_1', SubscriptionStatus::Active,
            periodStartsAt: Carbon::parse('2026-09-01'), periodEndsAt: Carbon::parse('2026-10-01'));

        $this->deliver(WebhookEventType::CheckoutCompleted, $this->completed('fcs_1'));

        $subscription = Subscription::where('provider_subscription_id', 'fsub_1')->firstOrFail();
        $this->assertSame('fpm_1', $subscription->paymentMethod?->provider_payment_method_id);
        $this->assertTrue(Carbon::parse('2026-10-01')->equalTo($subscription->current_period_ends_at));
    }

    public function test_a_trial_without_payment_details_starts_and_asks_for_them(): void
    {
        $this->openCheckout('fcs_1');
        $this->gateway->remote = new SubscriptionStateData('fsub_1', 'fcus_1', SubscriptionStatus::Active,
            trialStartsAt: now(), trialEndsAt: now()->addDays(14));

        $this->deliver(WebhookEventType::CheckoutCompleted, $this->completed('fcs_1'));

        $subscription = Subscription::where('provider_subscription_id', 'fsub_1')->firstOrFail();
        $this->assertTrue($subscription->trial_ends_at->isFuture());
        $this->assertFalse($subscription->hasPaymentMethod());
    }

    public function test_falling_behind_and_being_cancelled(): void
    {
        $this->subscription();

        $this->deliver(WebhookEventType::SubscriptionUpdated, new SubscriptionStateData('fsub_1', 'fcus_1', SubscriptionStatus::PastDue));
        $this->assertNotNull(Subscription::first()->grace_ends_at);

        $this->deliver(WebhookEventType::SubscriptionDeleted, new SubscriptionStateData('fsub_1', 'fcus_1', SubscriptionStatus::Cancelled));
        $this->assertSame(SubscriptionStatus::Cancelled, Subscription::first()->status);
    }

    public function test_a_payment_is_recorded(): void
    {
        $this->subscription();

        $this->deliver(WebhookEventType::PaymentSucceeded, new InvoicePaymentData('fcus_1', 'fsub_1', 'fpay_1', null, Currency::EUR, 2900));

        $this->assertDatabaseHas('payments', ['provider' => 'fake', 'provider_payment_id' => 'fpay_1', 'amount' => 2900]);
    }

    /** The refund can overtake the payment it refunds: it is retried, then applied. */
    public function test_a_refund_arriving_before_its_payment_is_retried(): void
    {
        $this->subscription();
        $refund = new RefundData('fcus_1', 'fpay_1', 2900, true);

        try {
            $this->deliver(WebhookEventType::PaymentRefunded, $refund);
            $this->fail('The early refund was acknowledged.');
        } catch (\RuntimeException) {
        }

        $this->deliver(WebhookEventType::PaymentSucceeded, new InvoicePaymentData('fcus_1', 'fsub_1', 'fpay_1', null, Currency::EUR, 2900));
        $this->deliver(WebhookEventType::PaymentRefunded, $refund);

        $this->assertSame(PaymentStatus::Refunded, Payment::where('provider_payment_id', 'fpay_1')->value('status'));
    }

    /** Not mentioning the card leaves it; clearing it removes it. */
    public function test_a_cleared_card_is_not_the_same_as_an_unmentioned_one(): void
    {
        $subscription = $this->subscription();
        $method = $subscription->payment_method_id;

        $this->deliver(WebhookEventType::SubscriptionUpdated, new SubscriptionStateData('fsub_1', 'fcus_1', SubscriptionStatus::Active, paymentMethodReference: new Optional));
        $this->assertSame($method, $subscription->fresh()->payment_method_id);

        $this->deliver(WebhookEventType::SubscriptionUpdated, new SubscriptionStateData('fsub_1', 'fcus_1', SubscriptionStatus::Active, paymentMethodReference: null));
        $this->assertNull($subscription->fresh()->payment_method_id);
    }

    public function test_the_return_fallback_completes_a_fulfillable_checkout(): void
    {
        $session = $this->openCheckout('fcs_1');
        $this->gateway->checkouts['fcs_1'] = $this->completed('fcs_1');
        $this->gateway->remote = new SubscriptionStateData('fsub_1', 'fcus_1', SubscriptionStatus::Active);

        $this->assertTrue($this->billing->fulfillCheckoutIfNeeded($session));
        $this->assertSame(CheckoutSessionStatus::Completed, $session->fresh()->status);
    }

    public function test_the_return_fallback_leaves_an_unfinished_checkout_alone(): void
    {
        $session = $this->openCheckout('fcs_1');
        $this->gateway->checkouts['fcs_1'] = $this->completed('fcs_1', fulfillable: false);

        $this->assertFalse($this->billing->fulfillCheckoutIfNeeded($session));
        $this->assertSame(CheckoutSessionStatus::Pending, $session->fresh()->status);
    }

    /** The return URL names our checkout, which every provider can carry back. */
    public function test_the_return_url_names_our_checkout_not_a_provider_template(): void
    {
        $this->actingAs($this->user)->post(route('billing.checkout.create'), ['price_id' => $this->price->id]);

        $session = CheckoutSession::latest('id')->firstOrFail();
        $this->assertSame(route('settings.billing').'?checkout_session='.$session->uuid, $this->gateway->lastCheckout?->successUrl);
    }

    public function test_the_buyer_returning_to_their_paid_checkout_completes_it(): void
    {
        $session = $this->openCheckout('fcs_1');
        $this->gateway->checkouts['fcs_1'] = $this->completed('fcs_1');

        $this->actingAs($this->user)->get(route('settings.billing', ['checkout_session' => $session->uuid]))
            ->assertRedirectContains('checkout=success');

        $this->assertSame(CheckoutSessionStatus::Completed, $session->fresh()->status);
    }

    /** An unguessable ID is not permission: only the buyer's own return fulfils. */
    public function test_someone_elses_checkout_is_not_completed_by_their_link(): void
    {
        $session = $this->openCheckout('fcs_1');
        $this->gateway->checkouts['fcs_1'] = $this->completed('fcs_1');

        $this->actingAs($this->createUser())->get(route('settings.billing', ['checkout_session' => $session->uuid]))
            ->assertRedirect(SettingsSection::url('billing'));

        $this->assertSame(CheckoutSessionStatus::Pending, $session->fresh()->status);
    }

    public function test_an_unknown_checkout_does_nothing(): void
    {
        $this->actingAs($this->user)->get(route('settings.billing', ['checkout_session' => 'not-a-real-uuid']))
            ->assertRedirect(SettingsSection::url('billing'));
    }

    public function test_returning_before_paying_completes_nothing(): void
    {
        $session = $this->openCheckout('fcs_1');
        $this->gateway->checkouts['fcs_1'] = $this->completed('fcs_1', fulfillable: false);

        $this->actingAs($this->user)->get(route('settings.billing', ['checkout_session' => $session->uuid]))
            ->assertRedirect(SettingsSection::url('billing'));

        $this->assertSame(CheckoutSessionStatus::Pending, $session->fresh()->status);
    }

    /** The provider cannot be asked: no success is claimed, and the webhook still completes it later. */
    public function test_returning_while_the_provider_is_down_claims_nothing(): void
    {
        Exceptions::fake();
        $session = $this->openCheckout('fcs_down');

        $this->actingAs($this->user)->get(route('settings.billing', ['checkout_session' => $session->uuid]))
            ->assertRedirect(SettingsSection::url('billing'));

        $this->assertSame(CheckoutSessionStatus::Pending, $session->fresh()->status);
        Exceptions::assertReported(GatewayOperationFailed::class);
    }

    private function failingHandOff(): CheckoutSession
    {
        Exceptions::fake();
        $this->gateway->failCheckout = true;

        $response = $this->actingAs($this->user)->post(route('billing.checkout.create'), ['price_id' => $this->price->id]);

        $session = CheckoutSession::latest('id')->firstOrFail();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Billing::Checkout', false)
            ->where('handoffFailed', true)
            ->where('session.uuid', $session->uuid));

        return $session;
    }

    /** The hand-off failed: the buyer stays on this checkout, with its request kept, and is told. */
    public function test_a_failed_hand_off_keeps_the_checkout_and_says_so(): void
    {
        $session = $this->failingHandOff();

        $this->assertSame(CheckoutSessionStatus::Pending, $session->fresh()->status);
        $this->assertNotNull($session->fresh()->success_url);
        Exceptions::assertReportedCount(1);
    }

    /** Try again resends the same checkout — same idempotency key, same request — not a new one. */
    public function test_trying_again_resends_the_same_checkout(): void
    {
        $session = $this->failingHandOff();
        $first = $this->gateway->lastCheckout;
        $this->gateway->failCheckout = false;

        $this->post(route('billing.checkout.retry', $session))->assertRedirect('https://fake.test/pay');

        $this->assertSame(1, CheckoutSession::count());
        $this->assertSame('checkout_'.$session->uuid, $this->gateway->lastCheckout->idempotencyKey);
        $this->assertSame($first->successUrl, $this->gateway->lastCheckout->successUrl);
        $this->assertSame($first->trialDays, $this->gateway->lastCheckout->trialDays);
    }

    public function test_only_the_buyer_can_retry_their_checkout(): void
    {
        $session = $this->failingHandOff();

        $this->actingAs($this->createUser())->post(route('billing.checkout.retry', $session))->assertForbidden();
    }

    public function test_a_finished_checkout_cannot_be_retried(): void
    {
        $session = $this->failingHandOff();
        $session->update(['status' => CheckoutSessionStatus::Completed]);

        $this->post(route('billing.checkout.retry', $session))->assertStatus(410);
    }

    public function test_an_expired_checkout_cannot_be_retried(): void
    {
        $session = $this->failingHandOff();
        $session->update(['expires_at' => now()->subMinute()]);

        $this->post(route('billing.checkout.retry', $session))->assertStatus(410);
    }
}

/** A provider with nothing in common with Stripe but the module's contract. */
class FakeGateway implements PaymentGatewayInterface
{
    public ?WebhookData $next = null;

    public ?SubscriptionStateData $remote = null;

    /** @var array<string, string> reference => payment method ID */
    public array $cards = [];

    /** @var array<string, CheckoutSessionData> */
    public array $checkouts = [];

    public ?CheckoutData $lastCheckout = null;

    public bool $failCheckout = false;

    public function createCustomer(CustomerData $data): string
    {
        return 'fcus_new';
    }

    public function retrieveSubscription(string $providerSubscriptionId): SubscriptionStateData
    {
        return $this->remote ?? new SubscriptionStateData($providerSubscriptionId, null, null);
    }

    public function retrieveCheckoutSession(string $providerSessionId): CheckoutSessionData
    {
        return $this->checkouts[$providerSessionId] ?? throw new GatewayOperationFailed('fake', 'read a checkout session', providerResourceId: $providerSessionId);
    }

    public function resolvePaymentMethod(string $reference): ?PaymentMethodData
    {
        return isset($this->cards[$reference])
            ? new PaymentMethodData($this->cards[$reference], PaymentMethodType::Card)
            : null;
    }

    public function expireCheckoutSession(string $providerSessionId): CheckoutExpiry
    {
        return CheckoutExpiry::Expired;
    }

    public function createCheckoutSession(CheckoutData $data): CheckoutResultData
    {
        $this->lastCheckout = $data;

        if ($this->failCheckout) {
            throw new GatewayOperationFailed('fake', 'create a checkout session');
        }

        return new CheckoutResultData(sessionId: 'fcs_new', url: 'https://fake.test/pay', provider: 'fake');
    }

    public function cancelSubscription(Subscription $subscription): ?\DateTimeInterface
    {
        return null;
    }

    public function resumeSubscription(Subscription $subscription): void {}

    public function getManagementUrl(Customer $customer): string
    {
        return 'https://fake.test/portal';
    }

    public function getPlanChangeUrl(Subscription $subscription): string
    {
        return 'https://fake.test/change';
    }

    public function verifyAndParseWebhook(Request $request): WebhookData
    {
        return $this->next;
    }

    public function listCatalog(): array
    {
        return [];
    }

    public function createProduct(Product $product): string
    {
        return 'fprod';
    }

    public function createPrice(Price $price, string $providerProductId): string
    {
        return 'fprice';
    }

    public function pushProductFeatures(Product $product): void {}
}
