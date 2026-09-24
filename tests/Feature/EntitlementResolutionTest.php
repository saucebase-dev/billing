<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;

/**
 * What a user may do is every plan they hold, combined: the free plan, the
 * plan of their current subscription, and each lifetime plan they bought.
 */
class EntitlementResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Product::factory()->free()->create(['entitlements' => ['limits' => ['projects' => 3]]]);

        $this->user = $this->createUser();
        $this->customer = Customer::factory()->for($this->user, 'owner')->create();
    }

    /** @param  array<string, mixed>  $attributes */
    private function subscribeTo(Product $plan, SubscriptionStatus $status = SubscriptionStatus::Active, array $attributes = []): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->create(['product_id' => $plan->id])->id,
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function paidPlan(): Product
    {
        return Product::factory()->create(['entitlements' => ['features' => ['exports' => true]]]);
    }

    private function buy(Product $plan, PaymentStatus $status = PaymentStatus::Succeeded): Payment
    {
        return Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->oneTime()->create(['product_id' => $plan->id])->id,
            'status' => $status,
        ]);
    }

    public function test_someone_with_nothing_bought_has_the_free_plan(): void
    {
        $this->assertSame(3, $this->user->planLimit('projects'));
        $this->assertFalse($this->user->canUseFeature('exports'));
    }

    public function test_a_guest_customer_record_is_not_needed_for_the_free_plan(): void
    {
        $this->assertSame(3, $this->createUser()->planLimit('projects'));
    }

    public function test_a_current_subscription_adds_its_plan(): void
    {
        $this->subscribeTo(Product::factory()->create(['entitlements' => ['features' => ['exports' => true], 'limits' => ['projects' => null]]]));

        $this->assertTrue($this->user->canUseFeature('exports'));
        $this->assertNull($this->user->planLimit('projects'));
    }

    /** A failed payment is a few days to fix a card, not the end of the plan. */
    public function test_a_subscription_behind_on_payment_counts_inside_its_grace_window(): void
    {
        $this->subscribeTo($this->paidPlan(), SubscriptionStatus::PastDue, ['grace_ends_at' => now()->addDay()]);

        $this->assertTrue($this->user->canUseFeature('exports'));
    }

    public function test_a_subscription_stops_counting_when_its_grace_window_closes(): void
    {
        $this->subscribeTo($this->paidPlan(), SubscriptionStatus::PastDue, ['grace_ends_at' => now()->subMinute()]);

        $this->assertFalse($this->user->canUseFeature('exports'));
    }

    /** Fail closed: a missing deadline is a bug, not permission to keep going. */
    public function test_a_subscription_behind_on_payment_with_no_deadline_counts_for_nothing(): void
    {
        $this->subscribeTo($this->paidPlan(), SubscriptionStatus::PastDue, ['grace_ends_at' => null]);

        $this->assertFalse($this->user->canUseFeature('exports'));
    }

    public function test_a_suspended_subscription_counts_for_nothing(): void
    {
        $this->subscribeTo($this->paidPlan(), SubscriptionStatus::Suspended, ['grace_ends_at' => now()->subDay()]);

        $this->assertFalse($this->user->canUseFeature('exports'));
    }

    /** The provider reports a trial as active, and it grants what it sells. */
    public function test_a_trialing_subscription_counts(): void
    {
        $this->subscribeTo($this->paidPlan(), SubscriptionStatus::Active, [
            'trial_starts_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(13),
        ]);

        $this->assertTrue($this->user->canUseFeature('exports'));
        $this->assertTrue($this->user->hasPaidPlan());
    }

    public function test_a_cancelled_subscription_does_not_count(): void
    {
        $this->subscribeTo(Product::factory()->create(['entitlements' => ['features' => ['exports' => true]]]), SubscriptionStatus::Cancelled);

        $this->assertFalse($this->user->canUseFeature('exports'));
    }

    public function test_a_lifetime_plan_counts_until_it_is_fully_refunded(): void
    {
        $payment = $this->buy(Product::factory()->lifetime()->create(['entitlements' => ['features' => ['exports' => true]]]));

        $this->assertTrue($this->user->canUseFeature('exports'));

        $payment->update(['status' => PaymentStatus::Refunded]);

        $this->assertFalse($this->user->fresh()->canUseFeature('exports'));
    }

    /** A one-off purchase is paid for and recorded, but it is not access. */
    public function test_a_one_off_purchase_grants_nothing(): void
    {
        $this->buy(Product::factory()->oneOff()->create(['entitlements' => ['features' => ['exports' => true]]]));

        $this->assertFalse($this->user->canUseFeature('exports'));
    }

    public function test_lifetime_and_a_subscription_combine(): void
    {
        $pro = Product::factory()->create(['entitlements' => ['limits' => ['projects' => null]]]);
        $this->buy(Product::factory()->lifetime($pro)->create(['entitlements' => ['limits' => ['projects' => null]]]));
        $this->subscribeTo(Product::factory()->create(['entitlements' => ['features' => ['sso' => true], 'limits' => ['members' => 25]]]));

        $this->assertNull($this->user->planLimit('projects'));
        $this->assertTrue($this->user->canUseFeature('sso'));
        $this->assertSame(25, $this->user->planLimit('members'));
    }

    /** Retiring a plan stops new sales; it never takes back what was bought. */
    public function test_an_archived_plan_still_grants_what_was_bought(): void
    {
        $plan = Product::factory()->lifetime()->create(['entitlements' => ['features' => ['exports' => true]]]);
        $this->buy($plan);

        $plan->update(['is_active' => false]);
        $plan->delete();

        $this->assertTrue($this->user->fresh()->canUseFeature('exports'));
    }

    /** Decides the "Upgrade" menu item and the free plan's card. */
    public function test_a_paid_plan_is_a_current_subscription_or_a_lifetime_plan(): void
    {
        $this->assertFalse($this->user->hasPaidPlan());

        $this->buy(Product::factory()->oneOff()->create());
        $this->assertFalse($this->user->fresh()->hasPaidPlan());

        $this->buy(Product::factory()->lifetime()->create());
        $this->assertTrue($this->user->fresh()->hasPaidPlan());
    }

    public function test_a_current_subscription_is_a_paid_plan(): void
    {
        $this->subscribeTo(Product::factory()->create());

        $this->assertTrue($this->user->hasPaidPlan());
    }
}
