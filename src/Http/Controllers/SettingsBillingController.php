<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Settings\BillingSettings;
use Saucebase\Core\Settings\SettingsSection;

class SettingsBillingController
{
    /**
     * Land the visitor on the billing section of the settings modal.
     *
     * Stripe returns here with a `session_id` after checkout, so the fulfilment
     * still has to run server-side before the panel is shown. The section itself
     * lives behind the `#settings/billing` fragment, which never reaches the
     * server.
     */
    public function show(Request $request, BillingService $billingService): RedirectResponse
    {
        $paid = false;

        if ($sessionId = $request->query('session_id')) {
            $paid = $this->belongsToUser($sessionId, $request->user()?->id)
                && $billingService->fulfillCheckoutIfNeeded($sessionId);
        }

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
     * The session ID arrives in the URL, so anyone can put someone else's in it.
     * Only the buyer's own return fulfils and congratulates; everyone else's
     * checkout is completed by its webhook.
     */
    private function belongsToUser(string $providerSessionId, ?int $userId): bool
    {
        return $userId !== null && CheckoutSession::where('provider', app(BillingSettings::class)->gateway)
            ->where('provider_session_id', $providerSessionId)
            ->whereHas('customer', fn ($query) => $query->where('user_id', $userId))
            ->exists();
    }
}
