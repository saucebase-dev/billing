<?php

namespace Modules\Billing\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Settings\BillingSettings;
use Symfony\Component\HttpFoundation\Response;

class CheckoutController
{
    public function __construct(
        private BillingService $billingService,
        private BillingSettings $settings,
    ) {}

    public function create(Request $request): Response
    {
        $validated = $request->validate([
            'price_id' => ['required', 'integer'],
        ]);

        $price = Price::purchasable()->find($validated['price_id']);

        if (! $price) {
            throw ValidationException::withMessages(['price_id' => __('This plan is not available.')]);
        }

        // Refused before a session exists; processCheckout() checks again for
        // the guest who signs in part-way through.
        if ($request->user()) {
            $this->billingService->assertCanBuy($request->user(), $price);
        }

        // Under the plan's row lock, the same one an admin edit takes: once this
        // pending session exists the plan's terms are fixed (Product::isSold()),
        // and the provider is only called after it does.
        $session = DB::transaction(function () use ($price) {
            Product::whereKey($price->product_id)->lockForUpdate()->first();

            return CheckoutSession::create([
                'price_id' => $price->id,
                'status' => CheckoutSessionStatus::Pending,
                'expires_at' => now()->addMinutes($this->settings->checkout_expire_after_minutes),
            ]);
        });

        // Straight to the gateway when we already know who is buying. Going via
        // the checkout route would work too, but an Inertia visit follows that
        // redirect over XHR and dies on the gateway's CORS policy; Inertia's own
        // location response tells the browser to navigate instead.
        if ($request->user() && $this->settings->redirect_to_gateway) {
            return $this->sendToGateway($session, $request->user());
        }

        return redirect()->route('billing.checkout', $session);
    }

    public function show(Request $request, CheckoutSession $checkoutSession): Response|InertiaResponse
    {
        abort_if($checkoutSession->status !== CheckoutSessionStatus::Pending, 410);
        abort_if($checkoutSession->expires_at?->isPast(), 410);

        $this->assertBelongsTo($checkoutSession, $request->user());

        $checkoutSession->load('price.product');

        // Straight to the gateway: it asks for the email, the address and the
        // promotion code itself, so our own page would only ask twice. The
        // session row is still written first, which is what the funnel reads.
        if ($this->settings->redirect_to_gateway) {
            return $this->sendToGateway($checkoutSession, $request->user());
        }

        return Inertia::render('Billing::Checkout', [
            'session' => $checkoutSession,
        ]);
    }

    public function store(Request $request, CheckoutSession $checkoutSession): Response
    {
        abort_if($checkoutSession->status !== CheckoutSessionStatus::Pending, 410);
        abort_if($checkoutSession->expires_at?->isPast(), 410);

        $this->assertBelongsTo($checkoutSession, $request->user());

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'coupon' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->billingService->processCheckout(
            session: $checkoutSession,
            user: $request->user(),
            successUrl: route('settings.billing').'?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: route('billing.checkout', $checkoutSession),
            billingDetails: ['email' => $validated['email']],
            coupon: $validated['coupon'] ?? null,
        );

        return Inertia::location($result->url);
    }

    /**
     * A session belongs to nobody until the first hand-off binds it to a customer.
     * After that only that customer may act on it, or one signed-in user could
     * take over another's pending checkout by visiting its URL.
     */
    private function assertBelongsTo(CheckoutSession $session, ?User $user): void
    {
        abort_if($session->customer_id && $session->customer?->user_id !== $user?->id, 403);
    }

    /**
     * Hand the buyer over to the payment provider.
     *
     * `Inertia::location()` rather than a plain redirect: it answers an Inertia
     * visit with a 409 telling the browser to navigate, and a normal visit with
     * an ordinary redirect. A plain redirect would be followed over XHR and
     * blocked by the provider's CORS policy.
     */
    private function sendToGateway(CheckoutSession $session, User $user): Response
    {
        $result = $this->billingService->processCheckout(
            session: $session,
            user: $user,
            successUrl: route('settings.billing').'?session_id={CHECKOUT_SESSION_ID}',
            // Not back here: this route hands off to the gateway, so cancelling
            // would bounce the buyer straight back to the payment page.
            cancelUrl: route('billing.plans'),
        );

        return Inertia::location($result->url);
    }
}
