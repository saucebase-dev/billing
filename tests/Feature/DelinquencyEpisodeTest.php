<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Events\AccessSuspended;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\Gateways\StripeEventMapper;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Settings\BillingSettings;
use Modules\Billing\Tests\Support\StripeWebhook;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * Falling behind on payment is one episode with one deadline: it never extends,
 * a suspension only shortens it, and only a recovery at the provider ends it.
 */
class DelinquencyEpisodeTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    private Customer $customer;

    private Subscription $subscription;

    /** @var list<WebhookData> */
    private array $deliveries = [];

    private int $delivered = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(StripeGateway::class);
        // The service is a singleton, so one mock answers every delivery in turn.
        $this->gateway->method('verifyAndParseWebhook')->willReturnCallback(function (): WebhookData {
            return array_shift($this->deliveries);
        });

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->billing = app(BillingService::class);
        app(BillingSettings::class)->fill(['grace_period_days' => 3])->save();

        $this->customer = Customer::factory()->create();
        $this->subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_episode',
        ]);
    }

    /** One delivery of `customer.subscription.updated` carrying trial dates. */
    private function providerReportsTrial(int $startsAt, int $endsAt): void
    {
        $this->deliveries[] = StripeWebhook::make(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_'.++$this->delivered,
            payload: ['id' => 'sub_episode', 'status' => 'trialing', 'trial_start' => $startsAt, 'trial_end' => $endsAt],
        );

        $this->billing->handleWebhook('stripe', request());
    }

    /** One delivery of `invoice.payment_succeeded` for this subscription. */
    private function invoicePaid(string $providerPaymentId = 'pi_recovery'): void
    {
        $this->deliveries[] = StripeWebhook::make(
            type: WebhookEventType::PaymentSucceeded,
            provider: 'stripe',
            providerEventId: 'evt_'.++$this->delivered,
            payload: [
                'id' => 'in_recovery',
                'payment_intent' => $providerPaymentId,
                'subscription' => 'sub_episode',
                'customer' => $this->customer->provider_customer_id,
                'currency' => 'eur',
                'amount_paid' => 2900,
            ],
        );

        $this->billing->handleWebhook('stripe', request());
    }

    /** What the provider says when the app asks it directly. */
    private function providerSnapshot(string $status, ?callable $before = null): void
    {
        $this->gateway->expects($this->atLeastOnce())->method('retrieveSubscription')->willReturnCallback(function () use ($status, $before): SubscriptionStateData {
            if ($before) {
                $before();
            }

            return StripeEventMapper::subscription(['id' => 'sub_episode', 'status' => $status]);
        });
    }

    /** One delivery of `customer.subscription.updated` saying what the provider thinks. */
    private function providerReports(string $status): void
    {
        $this->deliveries[] = StripeWebhook::make(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_'.++$this->delivered,
            payload: ['id' => 'sub_episode', 'status' => $status],
        );

        $this->billing->handleWebhook('stripe', request());
    }

    public function test_falling_behind_starts_the_grace_window(): void
    {
        $this->providerReports('past_due');

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertTrue($subscription->grace_ends_at->isSameDay(now()->addDays(3)));
        $this->assertTrue($subscription->grantsAccess());
    }

    public function test_a_second_failure_does_not_extend_the_window(): void
    {
        $this->providerReports('past_due');
        $deadline = $this->subscription->fresh()->grace_ends_at;

        $this->travel(2)->days();
        $this->providerReports('past_due');

        $this->assertTrue($deadline->equalTo($this->subscription->fresh()->grace_ends_at));
    }

    public function test_the_provider_suspending_early_ends_the_window_there(): void
    {
        $this->providerReports('past_due');

        $this->travel(1)->days();
        $this->providerReports('unpaid');

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);
        $this->assertFalse($subscription->grace_ends_at->isFuture());
        $this->assertFalse($subscription->grantsAccess());
    }

    /** Suspension is absorbing: nothing short of a recovery hands access back. */
    public function test_falling_behind_again_while_suspended_changes_nothing(): void
    {
        $this->providerReports('past_due');
        $this->providerReports('unpaid');

        $this->providerReports('past_due');

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);
        $this->assertFalse($subscription->grantsAccess());
    }

    public function test_suspension_with_no_window_running_is_dated_now(): void
    {
        $this->providerReports('unpaid');

        $subscription = $this->subscription->fresh();
        $this->assertNotNull($subscription->grace_ends_at);
        $this->assertFalse($subscription->grace_ends_at->isFuture());
    }

    public function test_recovering_ends_the_episode(): void
    {
        $this->providerReports('past_due');
        $this->providerReports('active');

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
    }

    public function test_recovering_from_suspension_restores_access(): void
    {
        $this->providerReports('unpaid');
        $this->providerReports('active');

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertTrue($subscription->grantsAccess());
    }

    /** A trial is active at the provider, and ends any episode the same way. */
    public function test_a_trial_counts_as_recovery(): void
    {
        $this->providerReports('past_due');
        $this->providerReports('trialing');

        $this->assertSame(SubscriptionStatus::Active, $this->subscription->fresh()->status);
    }

    public function test_a_state_change_bumps_the_revision(): void
    {
        $before = $this->subscription->state_revision;

        $this->providerReports('past_due');

        $this->assertGreaterThan($before, $this->subscription->fresh()->state_revision);
    }

    public function test_a_grace_period_of_zero_days_suspends_at_once(): void
    {
        app(BillingSettings::class)->fill(['grace_period_days' => 0])->save();

        $this->providerReports('past_due');

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);
        $this->assertFalse($subscription->grantsAccess());
    }

    public function test_a_paid_invoice_restores_a_subscription_the_provider_reports_active(): void
    {
        $this->providerReports('past_due');
        $this->providerSnapshot('active');

        $this->invoicePaid();

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
    }

    /** A late payment for an older invoice is not a recovery; the provider decides. */
    public function test_a_paid_invoice_does_not_restore_while_the_provider_still_reports_past_due(): void
    {
        $this->providerReports('past_due');
        $this->providerSnapshot('past_due');

        $this->invoicePaid();

        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription->fresh()->status);
    }

    public function test_a_paid_invoice_restores_a_suspended_subscription(): void
    {
        $this->providerReports('unpaid');
        $this->providerSnapshot('active');

        $this->invoicePaid();

        $this->assertTrue($this->subscription->fresh()->grantsAccess());
    }

    /** The first attempt saved the payment and then failed to read the provider. */
    public function test_a_payment_already_recorded_is_still_reconciled(): void
    {
        $this->providerReports('past_due');
        Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->subscription->id,
            'price_id' => Price::factory()->create()->id,
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_recovery',
            'status' => PaymentStatus::Succeeded,
        ]);
        $this->providerSnapshot('active');

        $this->invoicePaid();

        $this->assertSame(SubscriptionStatus::Active, $this->subscription->fresh()->status);
    }

    /** A webhook wrote the row mid-read: the snapshot is stale, so ask again. */
    public function test_a_snapshot_overtaken_by_a_newer_write_is_retried(): void
    {
        $this->providerReports('past_due');

        $overtakeOnce = function (): void {
            static $done = false;

            if (! $done) {
                $done = true;
                Subscription::whereKey($this->subscription->id)->increment('state_revision');
            }
        };

        $this->providerSnapshot('active', $overtakeOnce);

        $this->invoicePaid();

        $this->assertSame(SubscriptionStatus::Active, $this->subscription->fresh()->status);
    }

    /** Overtaken every time: hand the delivery back so the provider retries it. */
    public function test_a_snapshot_overtaken_every_time_gives_the_delivery_back(): void
    {
        $this->providerReports('past_due');
        $this->providerSnapshot('active', fn () => Subscription::whereKey($this->subscription->id)->increment('state_revision'));

        try {
            $this->invoicePaid();
            $this->fail('The delivery was swallowed.');
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseHas('webhook_events', ['provider_event_id' => 'evt_'.$this->delivered, 'processed_at' => null]);
    }

    public function test_trial_dates_are_mirrored_from_the_provider(): void
    {
        $endsAt = now()->addDays(14)->startOfSecond();

        $this->providerReportsTrial(now()->startOfSecond()->timestamp, $endsAt->timestamp);

        $subscription = $this->subscription->fresh();
        $this->assertNotNull($subscription->trial_starts_at);
        $this->assertTrue($endsAt->equalTo($subscription->trial_ends_at));
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    /** A suspension learnt by asking is still a suspension, and the customer hears of it. */
    public function test_a_suspension_found_by_asking_the_provider_is_announced(): void
    {
        $this->providerReports('past_due');
        $this->providerSnapshot('unpaid');
        Event::fake([AccessSuspended::class]);

        $this->invoicePaid();

        Event::assertDispatched(AccessSuspended::class);
    }
}
