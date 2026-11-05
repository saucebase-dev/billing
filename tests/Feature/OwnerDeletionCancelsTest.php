<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Tests\Support\Traits\BillsTheUser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/** Deleting a billing owner stops its charges: nobody is left to cancel them. */
#[AllowMockObjectsWithoutExpectations]
class OwnerDeletionCancelsTest extends TestCase
{
    use BillsTheUser;
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

    private function subscribedUser(array $attributes = []): array
    {
        $user = $this->createUser();
        $customer = Customer::factory()->for($user, 'owner')->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
            ...$attributes,
        ]);

        return [$user, $subscription];
    }

    public function test_deleting_the_owner_cancels_its_subscription(): void
    {
        [$user, $subscription] = $this->subscribedUser();
        $this->gateway->expects($this->once())->method('cancelSubscription');

        $user->delete();

        $this->assertNotNull($subscription->fresh()->cancelled_at);
    }

    /** Queued: a provider outage retries the cancel instead of failing the delete. */
    public function test_the_cancel_is_queued(): void
    {
        Queue::fake();
        [$user, $subscription] = $this->subscribedUser();

        $user->delete();

        Queue::assertPushed(CallQueuedClosure::class);
        $this->assertModelMissing($user);
        $this->assertNull($subscription->fresh()->cancelled_at);
    }

    public function test_a_subscription_already_cancelled_is_left_alone(): void
    {
        [$user] = $this->subscribedUser(['cancelled_at' => now()]);
        $this->gateway->expects($this->never())->method('cancelSubscription');

        $user->delete();
    }

    public function test_an_ended_subscription_is_left_alone(): void
    {
        [$user] = $this->subscribedUser(['status' => SubscriptionStatus::Cancelled]);
        $this->gateway->expects($this->never())->method('cancelSubscription');

        $user->delete();
    }

    public function test_an_owner_without_billing_deletes_as_before(): void
    {
        $user = $this->createUser();
        $this->gateway->expects($this->never())->method('cancelSubscription');

        $user->delete();

        $this->assertModelMissing($user);
    }
}
