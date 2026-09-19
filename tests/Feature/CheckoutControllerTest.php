<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Data\CustomerData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Settings\BillingSettings;
use Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $price = Price::factory()->create();
        $this->session = CheckoutSession::create([
            'price_id' => $price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addHours(24),
        ]);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('createCustomer')->willReturn('cus_test_123');
        $gateway->method('createCheckoutSession')->willReturn(
            new CheckoutResultData(sessionId: 'cs_test_123', url: 'https://stripe.com/checkout', provider: 'stripe'),
        );

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
    }

    public function test_checkout_requires_authentication(): void
    {
        $response = $this->post(route('billing.checkout.store', $this->session), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $response->assertRedirect(route('register'));
    }

    public function test_checkout_validates_required_fields(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post(route('billing.checkout.store', $this->session), []);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_authenticated_checkout_uses_existing_user(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post(route('billing.checkout.store', $this->session), [
            'email' => $user->email,
        ]);

        $response->assertRedirect('https://stripe.com/checkout');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * The form asks for an email and nothing else; the name comes from the
     * account and Stripe collects the billing address on its own page.
     */
    public function test_checkout_takes_the_name_from_the_account(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post(route('billing.checkout.store', $this->session), [
            'email' => 'billing@example.com',
        ]);

        $response->assertRedirect('https://stripe.com/checkout');

        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => 'billing@example.com',
        ]);
    }

    /** The price can stay active on a product the admin has switched off. */
    public function test_a_disabled_product_cannot_be_bought(): void
    {
        app(BillingSettings::class)->fill(['redirect_to_gateway' => true])->save();
        $user = $this->createUser();

        $this->session->price->product->update(['is_active' => false]);

        $this->actingAs($user)
            ->post(route('billing.checkout.create'), ['price_id' => $this->session->price_id])
            ->assertSessionHasErrors('price_id');

        $this->actingAs($user)
            ->get(route('billing.checkout', $this->session))
            ->assertNotFound();
    }

    /** The provider rejects a checkout naming a price it has never heard of. */
    public function test_a_price_the_provider_does_not_know_cannot_be_bought(): void
    {
        $user = $this->createUser();

        $this->session->price->update(['provider_price_id' => null]);

        $this->actingAs($user)
            ->post(route('billing.checkout.create'), ['price_id' => $this->session->price_id])
            ->assertSessionHasErrors('price_id');
    }

    public function test_opening_a_checkout_session_goes_straight_to_the_gateway(): void
    {
        app(BillingSettings::class)->fill(['redirect_to_gateway' => true])->save();
        $user = $this->createUser();

        $this->actingAs($user)
            ->get(route('billing.checkout', $this->session))
            ->assertRedirect('https://stripe.com/checkout');

        $this->assertDatabaseHas('checkout_sessions', [
            'id' => $this->session->id,
            'provider_session_id' => 'cs_test_123',
        ]);
    }

    public function test_the_module_checkout_page_is_shown_when_the_redirect_is_turned_off(): void
    {
        app(BillingSettings::class)->fill(['redirect_to_gateway' => false])->save();
        $user = $this->createUser();

        $this->actingAs($user)
            ->get(route('billing.checkout', $this->session))
            ->assertOk();
    }

    /**
     * A session is bound to a customer at the first hand-off. After that, opening
     * its URL as somebody else must not move it onto the visitor's account.
     */
    public function test_a_pending_session_cannot_be_taken_over_by_another_user(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner)->get(route('billing.checkout', $this->session));

        $intruder = $this->createUser();

        $this->actingAs($intruder)
            ->get(route('billing.checkout', $this->session))
            ->assertForbidden();

        $this->assertSame(
            $owner->id,
            $this->session->fresh()->customer->user_id,
        );
    }
}
