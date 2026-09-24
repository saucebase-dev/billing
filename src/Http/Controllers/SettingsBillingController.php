<?php

namespace Modules\Billing\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Services\BillingOwners;
use Modules\Billing\Services\BillingService;
use Saucebase\Core\Settings\SettingsSection;

class SettingsBillingController
{
    public function __construct(private BillingOwners $owners) {}

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
        $session = $this->ownCheckout($request->query('checkout_session'), $request->user());
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
     * unguessable ID is not permission. Only a checkout of the owner the user
     * manages fulfils and congratulates; any other is completed by its webhook.
     * With no account there is nothing of theirs, and a null `customer_id`
     * would match every checkout nobody has claimed yet.
     */
    private function ownCheckout(mixed $uuid, ?User $user): ?CheckoutSession
    {
        $owner = $user ? $this->owners->for($user) : null;
        $account = $owner?->canManageBilling($user) ? $owner->billingAccount() : null;

        if (! is_string($uuid) || ! Str::isUuid($uuid) || ! $account) {
            return null;
        }

        return CheckoutSession::where('uuid', $uuid)->where('customer_id', $account->id)->first();
    }
}
