<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Tests\TestCase;

/**
 * The invariants a plan keeps however it is edited — admin form, catalog
 * import or code — because purchases and access are decided from them.
 */
class PlanRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_at_most_one_free_plan(): void
    {
        Product::factory()->free()->create();

        $this->expectException(QueryException::class);

        Product::factory()->free()->create();
    }

    public function test_the_free_plan_cannot_be_deleted(): void
    {
        $free = Product::factory()->free()->create();

        $this->expectException(ValidationException::class);

        $free->delete();
    }

    public function test_a_lifetime_plan_replaces_a_subscription_plan(): void
    {
        $oneOff = Product::factory()->oneOff()->create();

        $this->expectException(ValidationException::class);

        Product::factory()->lifetime($oneOff)->create();
    }

    public function test_a_lifetime_plan_cannot_replace_itself(): void
    {
        $lifetime = Product::factory()->lifetime()->create();

        $this->expectException(ValidationException::class);

        $lifetime->update(['replaces_product_id' => $lifetime->id]);
    }

    public function test_only_a_lifetime_plan_replaces_another(): void
    {
        $pro = Product::factory()->create();

        $this->expectException(ValidationException::class);

        Product::factory()->create(['replaces_product_id' => $pro->id]);
    }

    /**
     * Handed to the provider but not paid yet: changing the kind now would
     * have the buyer pay for one thing and receive another.
     */
    public function test_a_plan_being_sold_cannot_change_kind(): void
    {
        $plan = Product::factory()->oneOff()->create();
        CheckoutSession::factory()->create([
            'price_id' => Price::factory()->oneTime()->create(['product_id' => $plan->id])->id,
            'status' => CheckoutSessionStatus::Pending,
        ]);

        $this->expectException(ValidationException::class);

        $plan->update(['kind' => PlanKind::Lifetime]);
    }

    public function test_a_plan_already_bought_keeps_its_slug(): void
    {
        $plan = Product::factory()->oneOff()->create();
        Payment::factory()->create(['price_id' => Price::factory()->oneTime()->create(['product_id' => $plan->id])->id]);

        $this->expectException(ValidationException::class);

        $plan->update(['slug' => 'renamed']);
    }

    public function test_an_abandoned_checkout_does_not_lock_the_plan(): void
    {
        $plan = Product::factory()->oneOff()->create();
        CheckoutSession::factory()->create([
            'price_id' => Price::factory()->oneTime()->create(['product_id' => $plan->id])->id,
            'status' => CheckoutSessionStatus::Expired,
        ]);

        $plan->update(['kind' => PlanKind::Lifetime]);

        $this->assertSame(PlanKind::Lifetime, $plan->fresh()->kind);
    }

    /** Entitlements stay editable after sales: changing them is how a plan grows. */
    public function test_a_plan_already_bought_can_change_its_entitlements(): void
    {
        $plan = Product::factory()->oneOff()->create();
        Payment::factory()->create(['price_id' => Price::factory()->oneTime()->create(['product_id' => $plan->id])->id]);

        $plan->update(['entitlements' => ['features' => ['exports' => true]]]);

        $this->assertTrue($plan->fresh()->entitlements()->allows('exports'));
    }

    public function test_entitlement_keys_are_snake_case(): void
    {
        $this->expectException(ValidationException::class);

        Product::factory()->create(['entitlements' => ['features' => ['Export Data' => true]]]);
    }

    public function test_a_limit_is_a_whole_number_or_unlimited(): void
    {
        $this->expectException(ValidationException::class);

        Product::factory()->create(['entitlements' => ['limits' => ['projects' => -1]]]);
    }
}
