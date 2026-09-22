<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\PlanActions;
use Tests\TestCase;

/**
 * The button each pricing card shows. `buy` only ever appears where checkout
 * would accept the price; the other actions only label what it refuses.
 */
class PlanActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Product $free;

    private Product $pro;

    private Product $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->customer = Customer::factory()->create(['user_id' => $this->user->id]);
        $this->free = Product::factory()->free()->create();
        Price::factory()->create(['product_id' => $this->free->id, 'amount' => 0]);
        $this->pro = Product::factory()->create();
        $this->team = Product::factory()->create();
        Price::factory()->create(['product_id' => $this->pro->id]);
        Price::factory()->create(['product_id' => $this->team->id]);
    }

    /**
     * @return array{priceActions: array<int, string>, productActions: array<int, string>}
     */
    private function actions(?User $owner, Product ...$plans): array
    {
        return app(PlanActions::class)->for($owner, collect($plans)->map->fresh(['prices']));
    }

    private function priceOf(Product $plan): Price
    {
        return $plan->prices()->firstOrFail();
    }

    private function subscribeTo(Product $plan): Subscription
    {
        return Subscription::factory()->create(['customer_id' => $this->customer->id, 'price_id' => $this->priceOf($plan)->id]);
    }

    private function ownLifetime(Product $replaces): Product
    {
        $lifetime = Product::factory()->lifetime($replaces)->create();
        Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->oneTime()->create(['product_id' => $lifetime->id])->id,
        ]);

        return $lifetime;
    }

    public function test_a_guest_signs_up_for_free_and_buys_the_rest(): void
    {
        $actions = $this->actions(null, $this->free, $this->pro);

        $this->assertSame('signup', $actions['priceActions'][$this->priceOf($this->free)->id]);
        $this->assertSame('buy', $actions['priceActions'][$this->priceOf($this->pro)->id]);
    }

    public function test_a_user_with_nothing_bought_is_on_free(): void
    {
        $this->assertSame('current', $this->actions($this->user, $this->free)['priceActions'][$this->priceOf($this->free)->id]);
    }

    public function test_a_subscriber_sees_their_plan_as_current_and_changes_to_others(): void
    {
        $this->subscribeTo($this->pro);

        $actions = $this->actions($this->user, $this->free, $this->pro, $this->team);

        $this->assertSame('included', $actions['priceActions'][$this->priceOf($this->free)->id]);
        $this->assertSame('current', $actions['priceActions'][$this->priceOf($this->pro)->id]);
        $this->assertSame('change', $actions['priceActions'][$this->priceOf($this->team)->id]);
    }

    public function test_the_plan_a_lifetime_replaces_is_included(): void
    {
        $lifetime = $this->ownLifetime($this->pro);

        $actions = $this->actions($this->user, $this->pro, $lifetime, $this->team);

        $this->assertSame('included', $actions['priceActions'][$this->priceOf($this->pro)->id]);
        $this->assertSame('current', $actions['priceActions'][$this->priceOf($lifetime)->id]);
        $this->assertSame('buy', $actions['priceActions'][$this->priceOf($this->team)->id]);
    }

    /** No overlap: another plan waits, and the ending one offers no change. */
    public function test_while_the_replaced_subscription_runs_out_other_plans_wait(): void
    {
        $this->subscribeTo($this->pro);
        $this->ownLifetime($this->pro);

        $actions = $this->actions($this->user, $this->pro, $this->team);

        $this->assertSame('current', $actions['priceActions'][$this->priceOf($this->pro)->id]);
        $this->assertSame('later', $actions['priceActions'][$this->priceOf($this->team)->id]);
    }

    public function test_a_price_checkout_would_refuse_is_never_offered(): void
    {
        $unpushed = Price::factory()->create(['product_id' => $this->pro->id, 'provider_price_id' => null]);
        $mismatched = Price::factory()->oneTime()->create(['product_id' => $this->team->id]);

        $actions = $this->actions(null, $this->pro, $this->team);

        $this->assertSame('unavailable', $actions['priceActions'][$unpushed->id]);
        $this->assertSame('unavailable', $actions['priceActions'][$mismatched->id]);
    }

    public function test_plans_without_prices_get_a_plan_action(): void
    {
        $enterprise = Product::factory()->create(['metadata' => ['cta_url' => 'mailto:sales@example.com']]);
        $planWithoutPrices = Product::factory()->create();
        $this->free->prices()->delete();

        $actions = $this->actions($this->user, $this->free, $enterprise, $planWithoutPrices);

        $this->assertSame('current', $actions['productActions'][$this->free->id]);
        $this->assertSame('contact', $actions['productActions'][$enterprise->id]);
        $this->assertSame('unavailable', $actions['productActions'][$planWithoutPrices->id]);
    }
}
