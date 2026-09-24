<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Services\BillingService;
use Saucebase\Core\Settings\SettingsSection;

class SettingsBillingController
{
    /**
     * Land the visitor on the billing section of the settings modal.
     *
     * The provider returns here with our `checkout_session` after checkout, so
     * the fulfilment still has to run server-side before the panel is shown.
     * The section itself lives behind the `#settings/billing` fragment, which
     * never reaches the server.
     */
    public function show(Request $request, BillingService $billingService): RedirectResponse
    {
        $session = $this->ownCheckout($request->query('checkout_session'), $request->user()?->id);
        $paid = $session && $billingService->fulfillCheckoutIfNeeded($session);

        // A query parameter rather than a flash message: the panel is addressed by
        // a URL fragment the server never sees, so it is reached by a fresh visit
        // that would have dropped the flash. The panel clears it after toasting.
        return redirect()->to(
            $paid
                ? route('dashboard').'?checkout=success#settings/billing'
                : SettingsSection::url('billing'),
        );
    }

    /**
     * The ID arrives in the URL, so anyone can put someone else's in it: an
     * unguessable ID is not permission. Only the buyer's own return fulfils and
     * congratulates; everyone else's checkout is completed by its webhook.
     */
    private function ownCheckout(mixed $uuid, ?int $userId): ?CheckoutSession
    {
        if (! is_string($uuid) || $userId === null) {
            return null;
        }

        return CheckoutSession::where('uuid', $uuid)
            ->whereHas('customer', fn ($query) => $query->where('user_id', $userId))
            ->first();
    }
}
