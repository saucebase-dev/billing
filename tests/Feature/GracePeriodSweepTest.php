<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * What suspends a subscription whose grace window has closed. Access is already
 * gone the moment the deadline passes; this is what makes it visible and says so.
 */
class GracePeriodSweepTest extends TestCase
{
    use RefreshDatabase;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(StripeGateway::class);

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
    }

    /** @param  array<string, mixed>  $attributes */
    private function subscription(array $attributes): Subscription
    {
        return Subscription::factory()->create($attributes);
    }

    public function test_a_closed_window_suspends(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->subHour(),
        ]);

        $this->artisan('billing:end-grace-periods')->assertExitCode(0);

        $this->assertSame(SubscriptionStatus::Suspended, $subscription->fresh()->status);
    }

    public function test_a_window_still_open_is_left_alone(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->addDay(),
        ]);

        $this->artisan('billing:end-grace-periods');

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()->status);
        $this->assertTrue($subscription->fresh()->grantsAccess());
    }

    /** A subscription that recovered keeps its old deadline until the next write clears it. */
    public function test_a_subscription_that_is_no_longer_behind_is_left_alone(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Active,
            'grace_ends_at' => now()->subDay(),
        ]);

        $this->artisan('billing:end-grace-periods');

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
    }

    public function test_a_cancelled_subscription_is_left_alone(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Cancelled,
            'grace_ends_at' => now()->subDay(),
        ]);

        $this->artisan('billing:end-grace-periods');

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()->status);
    }

    /** The provider owns dunning and may still recover the subscription. */
    public function test_the_provider_is_never_called(): void
    {
        $this->subscription(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->subHour()]);

        $this->gateway->expects($this->never())->method('cancelSubscription');
        $this->gateway->expects($this->never())->method('retrieveSubscription');

        $this->artisan('billing:end-grace-periods');
    }

    public function test_running_twice_suspends_once(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->subHour(),
        ]);

        $this->artisan('billing:end-grace-periods');
        $revision = $subscription->fresh()->state_revision;

        $this->artisan('billing:end-grace-periods');

        $this->assertSame($revision, $subscription->fresh()->state_revision);
    }

    /** The window closed, so nothing is owed on the subscription any more. */
    public function test_suspension_keeps_the_deadline_it_ended_on(): void
    {
        $deadline = now()->subHour()->startOfSecond();
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => $deadline,
        ]);

        $this->artisan('billing:end-grace-periods');

        $this->assertTrue($deadline->equalTo($subscription->fresh()->grace_ends_at));
    }
}
