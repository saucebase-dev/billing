<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Data\CustomerData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Events\GraceStarted;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Notifications\GraceStartedNotification;
use Modules\Billing\Services\BillingOwners;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Settings\BillingSection;
use Modules\Billing\Settings\BillingSettings;
use Modules\Billing\Tests\Support\TestWorkspace;
use Tests\TestCase;

/**
 * Billing belongs to an owner, which is the user unless the app says a user
 * acts for someone else — a workspace, here a model with its own table and a
 * ULID key. Reading the owner's plan is the resolver's call; changing its
 * billing also needs the owner to say the user may.
 */
class BillingOwnerTest extends TestCase
{
    use RefreshDatabase;

    private TestWorkspace $workspace;

    private User $manager;

    private User $member;

    /** @var list<CustomerData> */
    private array $createdCustomers = [];

    protected function setUp(): void
    {
        parent::setUp();

        TestWorkspace::createTable();

        $this->manager = $this->createUser();
        $this->member = $this->createUser();
        $this->workspace = TestWorkspace::create([
            'name' => 'Acme',
            'billing_email' => 'billing@acme.test',
            'members' => [(string) $this->manager->id => 'manager', (string) $this->member->id => 'member'],
        ]);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('createCustomer')->willReturnCallback(function (CustomerData $data): string {
            $this->createdCustomers[] = $data;

            return 'cus_'.count($this->createdCustomers);
        });
        $gateway->method('createCheckoutSession')->willReturn(
            new CheckoutResultData(sessionId: 'cs_test', url: 'https://provider.test/cs_test', provider: 'stripe'),
        );
        $gateway->method('getManagementUrl')->willReturn('https://provider.test/portal');

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        app(BillingSettings::class)->fill(['redirect_to_gateway' => true])->save();
    }

    /** Resolves on every call, from the database: a removed member is out on their next request. */
    private function billWorkspaces(): void
    {
        app(BillingOwners::class)->resolveUsing(function (User $user): ?TestWorkspace {
            $workspace = TestWorkspace::find($this->workspace->id);

            return $workspace?->isMember($user) ? $workspace : null;
        });
    }

    private function subscribeWorkspace(): Subscription
    {
        $customer = $this->workspace->billingCustomer()->create(['provider' => 'stripe', 'provider_customer_id' => 'cus_ws']);
        $plan = Product::factory()->create(['name' => 'Team', 'entitlements' => ['features' => ['exports' => true], 'limits' => ['projects' => 50]]]);

        return Subscription::factory()->create([
            'customer_id' => $customer->id,
            'price_id' => Price::factory()->create(['product_id' => $plan->id])->id,
            'status' => SubscriptionStatus::Active,
        ]);
    }

    private function pendingSession(?int $customerId = null): CheckoutSession
    {
        return CheckoutSession::create([
            'price_id' => Price::factory()->create()->id,
            'customer_id' => $customerId,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_a_customer_is_created_through_the_owners_relation_and_read_back_both_ways(): void
    {
        $customer = $this->workspace->billingCustomer()->create(['provider' => 'stripe']);

        $this->assertTrue($customer->owner->is($this->workspace));
        $this->assertTrue(TestWorkspace::find($this->workspace->id)->billingAccount()->is($customer));
    }

    public function test_owners_of_different_types_load_together_and_do_not_collide_on_the_same_id(): void
    {
        $user = $this->createUser();
        $twin = TestWorkspace::create(['id' => (string) $user->id, 'name' => 'Twin', 'billing_email' => 'twin@acme.test', 'members' => []]);

        $userCustomer = $user->billingCustomer()->create(['provider' => 'stripe']);
        $twinCustomer = $twin->billingCustomer()->create(['provider' => 'stripe']);

        $loaded = Customer::with('owner')->whereKey([$userCustomer->id, $twinCustomer->id])->get()->keyBy('id');

        $this->assertInstanceOf(User::class, $loaded[$userCustomer->id]->owner);
        $this->assertTrue($loaded[$userCustomer->id]->owner->is($user));
        $this->assertInstanceOf(TestWorkspace::class, $loaded[$twinCustomer->id]->owner);
        $this->assertTrue($loaded[$twinCustomer->id]->owner->is($twin));
        $this->assertTrue($user->fresh()->billingAccount()->is($userCustomer));
        $this->assertTrue($twin->fresh()->billingAccount()->is($twinCustomer));
    }

    public function test_owners_can_be_queried_by_whether_they_have_an_account(): void
    {
        $buyer = $this->createUser();
        $buyer->billingCustomer()->create(['provider' => 'stripe']);
        $this->workspace->billingCustomer()->create(['provider' => 'stripe']);

        $this->assertSame([$buyer->id], User::whereHas('billingCustomer')->pluck('id')->all());
        $this->assertSame(1, User::withCount('billingCustomer')->find($buyer->id)->billing_customer_count);
        $this->assertSame([$this->workspace->id], TestWorkspace::has('billingCustomer')->pluck('id')->all());
        $this->assertCount(2, User::with('billingCustomer')->get()->filter(fn (User $user) => $user->billingCustomer === null));
    }

    public function test_without_a_resolver_the_user_is_the_owner(): void
    {
        $price = Price::factory()->create();

        $this->actingAs($this->manager)->post(route('billing.checkout.create'), ['price_id' => $price->id])
            ->assertRedirect('https://provider.test/cs_test');

        $this->assertTrue(Customer::sole()->owner->is($this->manager));
    }

    public function test_a_managers_checkout_bills_the_workspace_with_the_buyers_details(): void
    {
        $this->billWorkspaces();
        $price = Price::factory()->create();

        $this->actingAs($this->manager)->post(route('billing.checkout.create'), ['price_id' => $price->id])
            ->assertRedirect('https://provider.test/cs_test');

        $this->assertTrue(Customer::sole()->owner->is($this->workspace));
        $this->assertSame($this->manager->email, $this->createdCustomers[0]->email);
        $this->assertNull($this->manager->fresh()->billingAccount());
    }

    public function test_members_get_the_workspaces_plan(): void
    {
        $this->billWorkspaces();
        $this->subscribeWorkspace();

        $owner = app(BillingOwners::class)->for($this->member);

        $this->assertTrue($owner?->canUseFeature('exports') ?? false);
        $this->assertSame(50, $owner->planLimit('projects'));
        $this->assertFalse($this->member->hasPaidPlan());
        $this->actingAs($this->member)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('billing.plan', 'Team')
                ->where('navigation', fn ($navigation) => ! str_contains(json_encode($navigation), 'upgrade')));
    }

    public function test_a_member_who_cannot_manage_billing_changes_nothing(): void
    {
        $this->billWorkspaces();
        $this->subscribeWorkspace();
        $session = $this->pendingSession();

        $this->actingAs($this->member);
        $this->post(route('billing.checkout.create'), ['price_id' => Price::factory()->create()->id])->assertForbidden();
        $this->get(route('billing.checkout', $session))->assertForbidden();
        $this->post(route('billing.checkout.store', $session), ['email' => 'x@acme.test'])->assertForbidden();
        $this->post(route('billing.checkout.retry', $session))->assertForbidden();
        $this->post(route('billing.subscription.cancel'))->assertForbidden();
        $this->post(route('billing.subscription.resume'))->assertForbidden();
        $this->get(route('billing.portal'))->assertForbidden();
        $this->get(route('billing.plan.change'))->assertForbidden();
        $this->assertFalse(app(BillingSection::class)->visible());
        $this->assertSame(0, CheckoutSession::whereNotNull('customer_id')->count());
    }

    public function test_the_billing_panel_shows_for_a_manager(): void
    {
        $this->billWorkspaces();
        $this->subscribeWorkspace();

        $this->actingAs($this->manager);

        $this->assertTrue(app(BillingSection::class)->visible());
        $this->assertNotNull(app(BillingSection::class)->props()['subscription']);
    }

    public function test_a_removed_member_loses_the_plan_on_their_next_request(): void
    {
        $this->billWorkspaces();
        $this->subscribeWorkspace();
        $this->assertTrue(app(BillingOwners::class)->for($this->member)?->canUseFeature('exports') ?? false);

        $this->workspace->update(['members' => [(string) $this->manager->id => 'manager']]);

        $this->assertNull(app(BillingOwners::class)->for($this->member));
        $this->actingAs($this->member)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('billing.plan', null)
                ->where('navigation', fn ($navigation) => ! str_contains(json_encode($navigation), 'upgrade')));
        $this->post(route('billing.checkout.create'), ['price_id' => Price::factory()->create()->id])->assertForbidden();
        $this->get(route('billing.plans'))->assertOk();
    }

    public function test_a_resolver_answering_with_something_that_is_not_an_owner_is_no_owner(): void
    {
        app(BillingOwners::class)->resolveUsing(fn () => new \stdClass);

        $this->assertNull(app(BillingOwners::class)->for($this->manager));
    }

    public function test_a_guest_still_starts_a_checkout_and_is_sent_to_register(): void
    {
        $this->billWorkspaces();
        $price = Price::factory()->create();

        $response = $this->post(route('billing.checkout.create'), ['price_id' => $price->id]);

        $session = CheckoutSession::sole();
        $response->assertRedirect(route('billing.checkout', $session));
        $this->assertNull($session->customer_id);
    }

    public function test_a_checkout_bound_to_one_workspace_is_refused_while_acting_for_another(): void
    {
        $other = TestWorkspace::create(['name' => 'Other', 'billing_email' => 'b@other.test', 'members' => [(string) $this->manager->id => 'manager']]);
        $session = $this->pendingSession($other->billingCustomer()->create(['provider' => 'stripe'])->id);
        $this->billWorkspaces();

        $this->actingAs($this->manager)->post(route('billing.checkout.retry', $session))->assertForbidden();
    }

    public function test_the_return_fulfils_only_the_managed_owners_checkout(): void
    {
        $this->billWorkspaces();
        $service = $this->mock(BillingService::class);
        $service->shouldNotReceive('fulfillCheckoutIfNeeded');

        $ownerless = $this->pendingSession();
        $someoneElses = $this->pendingSession(Customer::factory()->create()->id);

        $this->actingAs($this->manager)->get(route('settings.billing', ['checkout_session' => $ownerless->uuid]))->assertRedirect();
        $this->actingAs($this->manager)->get(route('settings.billing', ['checkout_session' => $someoneElses->uuid]))->assertRedirect();
    }

    public function test_the_owner_sees_the_customer_it_just_got(): void
    {
        $this->billWorkspaces();
        $workspace = app(BillingOwners::class)->managedBy($this->manager);
        $this->assertNull($workspace->billingAccount());

        app(BillingService::class)->processCheckout($this->pendingSession(), $workspace, $this->manager, 'https://app.test/ok', 'https://app.test/no');

        $this->assertNotNull($workspace->billingAccount());
    }

    public function test_a_second_managers_checkout_keeps_the_billing_contact(): void
    {
        $this->billWorkspaces();
        $customer = $this->workspace->billingCustomer()->create(['provider' => 'stripe', 'provider_customer_id' => 'cus_ws', 'name' => 'Acme Ltd', 'email' => 'billing@acme.test']);
        $second = $this->createUser();
        $this->workspace->update(['members' => [...$this->workspace->members, (string) $second->id => 'manager']]);

        $this->actingAs($second)->post(route('billing.checkout.create'), ['price_id' => Price::factory()->create()->id]);
        $this->assertSame(['Acme Ltd', 'billing@acme.test'], [$customer->fresh()->name, $customer->fresh()->email]);

        app(BillingSettings::class)->fill(['redirect_to_gateway' => false])->save();
        $this->post(route('billing.checkout.store', $this->pendingSession()), ['email' => 'accounts@acme.test']);
        $this->assertSame('accounts@acme.test', $customer->fresh()->email);
    }

    public function test_billing_emails_go_to_the_owner(): void
    {
        Notification::fake();
        $subscription = $this->subscribeWorkspace();

        event(new GraceStarted($subscription));

        Notification::assertSentTo($this->workspace, GraceStartedNotification::class);
    }

    public function test_an_owner_without_an_email_of_its_own_is_mailed_at_its_billing_address(): void
    {
        $notification = new GraceStartedNotification($this->subscribeWorkspace());

        $this->assertSame('billing@acme.test', $this->workspace->routeNotificationFor('mail', $notification));
        $this->assertStringContainsString('Acme', $notification->toMail($this->workspace)->greeting);
    }

    public function test_a_soft_deleted_owner_keeps_its_billing_and_a_force_deleted_one_lets_go(): void
    {
        $customer = $this->workspace->billingCustomer()->create(['provider' => 'stripe']);

        $this->workspace->delete();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'owner_id' => $this->workspace->id]);

        $this->workspace->restore();
        $this->assertTrue($customer->fresh()->owner->is($this->workspace));

        $this->workspace->forceDelete();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'owner_type' => null, 'owner_id' => null]);
    }
}
