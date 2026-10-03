<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Exceptions\InvalidWebhookSignatureException;
use Modules\Billing\Exceptions\WebhookDependencyNotReadyException;
use Modules\Billing\Services\WebhookHandler;

/**
 * What the provider hears back. A 400 tells it never to send this again; a 503
 * identifies a known temporary dependency; a 500 leaves every unexpected failure
 * retryable. Bodies stay empty: nothing about the app goes back over the wire.
 */
class WebhookController
{
    public function __construct(
        private WebhookHandler $webhooks,
    ) {}

    public function __invoke(string $provider, Request $request): Response
    {
        try {
            $this->webhooks->handle($provider, $request);

            return response()->noContent(200);
        } catch (InvalidWebhookSignatureException $e) {
            Log::warning('Webhook rejected: signature', $e->context());

            return response()->noContent(400);
        } catch (WebhookDependencyNotReadyException $e) {
            // Expected: providers do not order their events. The resend lands
            // once the row it needs exists, so this is not reported.
            Log::info('Webhook deferred: dependency not ready', $e->context());

            return response()->noContent(Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            // The one place a webhook failure is reported. Never acknowledged:
            // the provider's resend is how anything left undone gets done.
            report($e);

            return response()->noContent(500);
        }
    }
}
