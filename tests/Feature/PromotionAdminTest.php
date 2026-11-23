<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Billing\Data\PromotionData;
use Modules\Billing\Filament\Resources\Customers\Pages\ViewCustomer;
use Modules\Billing\Filament\Resources\Promotions\Pages\ManagePromotions;
use Modules\Billing\Filament\Resources\Promotions\PromotionResource;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Promotion;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\MockObject\Stub;
use Tests\TestCase;

/** The admin attaches a provider's promotion code to prices; the provider's terms come with it. */
class PromotionAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @var StripeGateway&Stub */
    private StripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createStub(StripeGateway::class);
        $manager = $this->createStub(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    private function launch(): PromotionData
    {
        return new PromotionData(
            providerPromotionCodeId: 'promo_launch',
            code: 'LAUNCH',
            percentOff: null,
            amountOff: 3000,
            currency: 'USD',
            duration: 'once',
            durationInMonths: null,
            maxRedemptions: 50,
            timesRedeemed: 4,
            expiresAt: null,
            active: true,
        );
    }

    public function test_a_code_is_looked_up_and_attached_to_the_chosen_prices(): void
    {
        $this->gateway->method('findPromotion')->willReturn($this->launch());
        [$yearly, $monthly] = Price::factory()->count(2)->create();

        Livewire::test(ManagePromotions::class)
            ->callAction('create', data: ['code' => 'LAUNCH', 'price_ids' => [$yearly->id]])
            ->assertHasNoFormErrors();

        $promotion = Promotion::sole();
        $this->assertSame('promo_launch', $promotion->provider_promotion_code_id);
        $this->assertSame(50, $promotion->max_redemptions);
        $this->assertSame(4, $promotion->times_redeemed);
        $this->assertSame($promotion->id, $yearly->fresh()->promotion_id);
        $this->assertNull($monthly->fresh()->promotion_id);
    }

    public function test_a_code_the_provider_does_not_have_is_refused(): void
    {
        $this->gateway->method('findPromotion')->willReturn(null);

        Livewire::test(ManagePromotions::class)
            ->callAction('create', data: ['code' => 'NOPE', 'price_ids' => [Price::factory()->create()->id]]);

        $this->assertDatabaseCount('promotions', 0);
    }

    /** One promotion per price: choosing a price for this one takes it from the other. */
    public function test_editing_moves_prices_between_promotions(): void
    {
        $old = Promotion::factory()->create();
        $new = Promotion::factory()->create();
        $kept = Price::factory()->create(['promotion_id' => $old->id]);
        $moved = Price::factory()->create(['promotion_id' => $old->id]);

        Livewire::test(ManagePromotions::class)
            ->callAction(TestAction::make('edit')->table($new), data: ['price_ids' => [$moved->id]])
            ->assertHasNoFormErrors();

        $this->assertSame($old->id, $kept->fresh()->promotion_id);
        $this->assertSame($new->id, $moved->fresh()->promotion_id);
    }

    public function test_unticking_a_price_stops_promoting_it(): void
    {
        $promotion = Promotion::factory()->create();
        $price = Price::factory()->create(['promotion_id' => $promotion->id]);

        Livewire::test(ManagePromotions::class)
            ->callAction(TestAction::make('edit')->table($promotion), data: ['price_ids' => []]);

        $this->assertNull($price->fresh()->promotion_id);
    }

    public function test_only_billing_managers_see_promotions(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(PromotionResource::canAccess());
    }

    public function test_a_customers_payments_show_the_discount_and_code(): void
    {
        $payment = Payment::factory()->create(['amount' => 14900, 'currency' => 'USD', 'discount_amount' => 3000, 'promotion_code' => 'LAUNCH']);

        Livewire::test(ViewCustomer::class, ['record' => $payment->customer_id])
            ->assertSee('LAUNCH')
            ->assertSee('$30.00');
    }
}
