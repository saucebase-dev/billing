<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Enums\PurchaseRefusal;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\PurchaseEligibility;
use Tests\TestCase;

/**
 * The one answer to "may this owner buy this price", used by checkout and by
 * the pricing page alike so the two can never disagree.
 */
class PurchaseEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Product $pro;

    private Product $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->customer = Customer::factory()->for($this->user, 'owner')->create();
        $this->pro = Product::factory()->create();
        $this->team = Product::factory()->create();
    }

    private function check(Price $price, ?User $owner = null): ?PurchaseRefusal
    {
        return app(PurchaseEligibility::class)->check($owner ?? $this->user, $price);
    }

    private function monthly(Product $plan): Price
    {
        return Price::factory()->create(['product_id' => $plan->id]);
    }

    private function oneTime(Product $plan): Price
    {
        return Price::factory()->oneTime()->create(['product_id' => $plan->id]);
    }

    private function subscribeTo(Product $plan): Subscription
    {
        return Subscription::factory()->create(['customer_id' => $this->customer->id, 'price_id' => $this->monthly($plan)->id]);
    }

    /** Behind on payment and out of grace: still their plan, still one subscription. */
    private function suspend(Subscription $subscription): void
    {
        $subscription->update(['status' => SubscriptionStatus::Suspended, 'grace_ends_at' => now()->subDay()]);
    }

    private function own(Product $lifetime): void
    {
        Payment::factory()->create(['customer_id' => $this->customer->id, 'price_id' => $this->oneTime($lifetime)->id]);
    }

    public function test_anyone_may_buy_a_subscription_they_do_not_have(): void
    {
        $this->assertNull($this->check($this->monthly($this->pro)));
        $this->assertNull($this->check($this->monthly($this->pro), $this->createUser()));
    }

    public function test_the_free_plan_is_never_sold(): void
    {
        $this->assertSame(PurchaseRefusal::NotForSale, $this->check($this->monthly(Product::factory()->free()->create())));
    }

    public function test_an_inactive_price_or_plan_cannot_be_bought(): void
    {
        $this->assertSame(PurchaseRefusal::Unavailable, $this->check(Price::factory()->inactive()->create(['product_id' => $this->pro->id])));
        $this->assertSame(PurchaseRefusal::Unavailable, $this->check($this->monthly(Product::factory()->create(['is_active' => false]))));
    }

    public function test_a_price_the_provider_does_not_know_cannot_be_bought(): void
    {
        $this->assertSame(PurchaseRefusal::Unavailable, $this->check(Price::factory()->create(['product_id' => $this->pro->id, 'provider_price_id' => null])));
    }

    /** Catalog imports bypass the admin form, so the kind is checked at checkout too. */
    public function test_a_price_that_does_not_fit_its_plan_kind_cannot_be_bought(): void
    {
        $this->assertSame(PurchaseRefusal::Unavailable, $this->check($this->oneTime($this->pro)));
        $this->assertSame(PurchaseRefusal::Unavailable, $this->check($this->monthly(Product::factory()->oneOff()->create())));
    }

    public function test_the_plan_already_subscribed_to_is_current(): void
    {
        $this->subscribeTo($this->pro);

        $this->assertSame(PurchaseRefusal::Current, $this->check($this->monthly($this->pro)));
    }

    /** One subscription per owner; another plan is a change, at the provider. */
    public function test_a_subscriber_changes_plan_instead_of_buying_another(): void
    {
        $this->subscribeTo($this->pro);

        $this->assertSame(PurchaseRefusal::ChangeInstead, $this->check($this->monthly($this->team)));
    }

    public function test_a_subscriber_may_buy_lifetime(): void
    {
        $this->subscribeTo($this->pro);

        $this->assertNull($this->check($this->oneTime(Product::factory()->lifetime($this->pro)->create())));
    }

    public function test_a_lifetime_plan_already_owned_is_current(): void
    {
        $lifetime = Product::factory()->lifetime($this->pro)->create();
        $this->own($lifetime);

        $this->assertSame(PurchaseRefusal::Current, $this->check($this->oneTime($lifetime)));
    }

    public function test_the_plan_a_lifetime_replaces_is_included(): void
    {
        $this->own(Product::factory()->lifetime($this->pro)->create());

        $this->assertSame(PurchaseRefusal::Included, $this->check($this->monthly($this->pro)));
    }

    /** Lifetime covers its own plan only: a higher tier is still for sale. */
    public function test_a_lifetime_owner_may_subscribe_to_another_plan(): void
    {
        $this->own(Product::factory()->lifetime($this->pro)->create());

        $this->assertNull($this->check($this->monthly($this->team)));
    }

    /** The replaced subscription runs out its paid period first; no overlap. */
    public function test_another_plan_waits_while_the_replaced_subscription_runs_out(): void
    {
        $this->subscribeTo($this->pro);
        $this->own(Product::factory()->lifetime($this->pro)->create());

        $this->assertSame(PurchaseRefusal::AfterCurrentEnds, $this->check($this->monthly($this->team)));
    }

    public function test_a_one_off_can_be_bought_again_and_again(): void
    {
        $oneOff = Product::factory()->oneOff()->create();
        Payment::factory()->create(['customer_id' => $this->customer->id, 'price_id' => $this->oneTime($oneOff)->id]);

        $this->assertNull($this->check($this->oneTime($oneOff)));
    }

    public function test_a_suspended_subscriber_changes_plan_rather_than_buying_a_second(): void
    {
        $this->suspend($this->subscribeTo($this->pro));

        $this->assertSame(PurchaseRefusal::ChangeInstead, $this->check($this->monthly($this->team)));
    }

    public function test_a_suspended_subscriber_cannot_buy_the_plan_they_already_have(): void
    {
        $this->suspend($this->subscribeTo($this->pro));

        $this->assertSame(PurchaseRefusal::Current, $this->check($this->monthly($this->pro)));
    }
}
