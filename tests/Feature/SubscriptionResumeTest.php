<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Modules\Billing\Actions\ResumeSubscription;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Events\SubscriptionResumed;
use Modules\Billing\Exceptions\GatewayOperationFailedException;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SubscriptionResumeTest extends TestCase
{
    use RefreshDatabase;

    /** @var PaymentGatewayInterface&MockObject */
    private PaymentGatewayInterface $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(PaymentGatewayInterface::class);

        $manager = $this->createStub(PaymentGatewayManager::class);
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
    }

    public function test_resume_subscription_requires_auth(): void
    {
        $response = $this->post(route('billing.subscription.resume'));

        $response->assertRedirect(route('login'));
    }

    public function test_resume_subscription_calls_billing_service(): void
    {
        $user = $this->createUser();
        $customer = Customer::factory()->for($user, 'owner')->create();
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
            'cancelled_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->gateway->expects($this->once())
            ->method('resumeSubscription');

        $response = $this->actingAs($user)->post(route('billing.subscription.resume'));

        $response->assertRedirect();
    }

    public function test_resume_subscription_updates_local_state(): void
    {
        $user = $this->createUser();
        $customer = Customer::factory()->for($user, 'owner')->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
            'cancelled_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->gateway->expects($this->once())
            ->method('resumeSubscription');

        $this->actingAs($user)->post(route('billing.subscription.resume'));

        $subscription->refresh();

        $this->assertNull($subscription->cancelled_at);
        $this->assertNull($subscription->ends_at);
        $this->assertEquals(SubscriptionStatus::Active, $subscription->status);
    }

    public function test_resume_returns_404_when_no_pending_cancellation(): void
    {
        $user = $this->createUser();
        $customer = Customer::factory()->for($user, 'owner')->create();
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
            'cancelled_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('billing.subscription.resume'));

        $response->assertNotFound();
    }

    public function test_a_provider_failure_on_resume_is_explained_and_changes_nothing(): void
    {
        Exceptions::fake();
        $user = $this->createUser();
        $customer = Customer::factory()->for($user, 'owner')->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
            'cancelled_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
        $this->gateway->method('resumeSubscription')->willThrowException(new GatewayOperationFailedException('stripe', 'resume a subscription'));

        $this->actingAs($user)->from(route('dashboard'))->post(route('billing.subscription.resume'))->assertRedirect(route('dashboard'));

        $this->assertNotNull($subscription->fresh()->cancelled_at);
        Exceptions::assertReportedCount(1);
    }

    /** A subscription is resumed through the gateway it was bought on, not the default. */
    public function test_resume_uses_the_subscriptions_own_gateway(): void
    {
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->expects($this->once())->method('driver')->with('paddle')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $subscription = Subscription::factory()->create([
            'provider' => 'paddle',
            'status' => SubscriptionStatus::Active,
            'cancelled_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        app(ResumeSubscription::class)->handle($subscription);

        $this->assertNull($subscription->fresh()->cancelled_at);
    }

    public function test_resuming_announces_it(): void
    {
        Event::fake([SubscriptionResumed::class]);
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'cancelled_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        app(ResumeSubscription::class)->handle($subscription);

        Event::assertDispatched(SubscriptionResumed::class);
    }

    /** The portal opens on the customer's own gateway, not the default. */
    public function test_the_billing_portal_uses_the_customers_own_gateway(): void
    {
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->expects($this->once())->method('driver')->with('paddle')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
        $this->gateway->method('getManagementUrl')->willReturn('https://provider.test/portal');

        $user = $this->createUser();
        Customer::factory()->for($user, 'owner')->create(['provider' => 'paddle']);

        $this->actingAs($user)->get(route('billing.portal'))->assertRedirect('https://provider.test/portal');
    }
}
