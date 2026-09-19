<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Services\BillingService;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        } catch (HttpException $e) {
            Log::warning('Webhook rejected', [
                'provider' => $provider,
                'status' => $e->getStatusCode(),
                'error' => $e->getMessage(),
            ]);

            return response()->noContent($e->getStatusCode());
        } catch (\Throwable $e) {
            // A 5xx is what makes the provider try again, which is the recovery
            // for events that arrive before the ones they depend on.
            Log::error('Webhook processing failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->noContent(500);
        }
    }
}
