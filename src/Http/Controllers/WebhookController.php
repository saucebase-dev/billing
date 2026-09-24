<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Exceptions\InvalidWebhookSignature;
use Modules\Billing\Exceptions\WebhookDependencyNotReady;
use Modules\Billing\Services\BillingService;

/**
 * What the provider hears back. A 400 tells it never to send this again; a 500
 * tells it to try again later, which is the recovery for every other failure.
 * Bodies stay empty: nothing about the app goes back over the wire.
 */
class WebhookController
{
    public function __construct(
        private BillingService $billingService,
    ) {}

    public function __invoke(string $provider, Request $request): Response
    {
        try {
            $this->billingService->handleWebhook($provider, $request);

            return response()->noContent(200);
        } catch (InvalidWebhookSignature $e) {
            Log::warning('Webhook rejected: signature', $e->context());

            return response()->noContent(400);
        } catch (WebhookDependencyNotReady $e) {
            // Expected: providers do not order their events. The resend lands
            // once the row it needs exists, so this is not reported.
            Log::info('Webhook deferred: dependency not ready', $e->context());

            return response()->noContent(500);
        } catch (\Throwable $e) {
            // The one place a webhook failure is reported. Never acknowledged:
            // the provider's resend is how anything left undone gets done.
            report($e);

            return response()->noContent(500);
        }
    }
}
