<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Enums\CheckoutExpiry;
use Modules\Billing\Exceptions\GatewayOperationFailed;
use Modules\Billing\Exceptions\ProviderError;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\Gateways\StripeGateway;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidArgumentException;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Failures at the Stripe boundary: what they turn into, and what they must not.
 */
class StripeGatewayErrorTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, array{string, int}>  $responses  Per URL path: body and status. */
    private function gateway(array $responses): StripeGateway
    {
        ApiRequestor::setHttpClient(new class($responses) implements ClientInterface
        {
            public function __construct(private array $responses) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                [$body, $status] = $this->responses[parse_url($absUrl, PHP_URL_PATH)];

                return [$body, $status, ['Request-Id' => 'req_trace']];
            }
        });

        return new StripeGateway(new StripeClient(['api_key' => 'sk_test_stub', 'max_network_retries' => 0]));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private static function error(string $type, string $message, ?string $code = null): string
    {
        return json_encode(['error' => array_filter(['type' => $type, 'message' => $message, 'code' => $code])]);
    }

    public function test_a_provider_error_becomes_a_gateway_failure_with_its_trace(): void
    {
        $gateway = $this->gateway(['/v1/subscriptions/sub_1' => [self::error('invalid_request_error', 'No such subscription', 'resource_missing'), 404]]);

        try {
            $gateway->retrieveSubscription('sub_1');
            $this->fail('No exception.');
        } catch (GatewayOperationFailed $e) {
            $this->assertSame('billing.gateway_operation_failed', $e->context()['billing_error_id']);
            $this->assertSame('stripe', $e->context()['provider']);
            $this->assertSame('req_trace', $e->context()['provider_request_id']);
            $this->assertSame('resource_missing', $e->context()['provider_code']);
            $this->assertSame(404, $e->context()['http_status']);
            $this->assertInstanceOf(ProviderError::class, $e->getPrevious());
        }
    }

    /** A bug in how the SDK is called is a bug, not a provider outage. */
    public function test_a_programming_error_is_not_dressed_up_as_a_provider_failure(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->gateway([])->resumeSubscription(new Subscription(['provider_subscription_id' => '']));
    }

    public function test_an_expired_checkout_is_confirmed_expired(): void
    {
        $gateway = $this->gateway(['/v1/checkout/sessions/cs_1/expire' => ['{"id": "cs_1", "object": "checkout.session", "status": "expired"}', 200]]);

        $this->assertSame(CheckoutExpiry::Expired, $gateway->expireCheckoutSession('cs_1'));
    }

    /** Refused because it already expired — including by an earlier call whose answer was lost. */
    public function test_a_checkout_that_already_expired_is_confirmed_expired(): void
    {
        $gateway = $this->gateway([
            '/v1/checkout/sessions/cs_1/expire' => [self::error('invalid_request_error', 'Session is not open'), 400],
            '/v1/checkout/sessions/cs_1' => ['{"id": "cs_1", "object": "checkout.session", "status": "expired"}', 200],
        ]);

        $this->assertSame(CheckoutExpiry::Expired, $gateway->expireCheckoutSession('cs_1'));
    }

    public function test_a_paid_checkout_is_completed_not_expired(): void
    {
        $gateway = $this->gateway([
            '/v1/checkout/sessions/cs_1/expire' => [self::error('invalid_request_error', 'Session is not open'), 400],
            '/v1/checkout/sessions/cs_1' => ['{"id": "cs_1", "object": "checkout.session", "status": "complete"}', 200],
        ]);

        $this->assertSame(CheckoutExpiry::Completed, $gateway->expireCheckoutSession('cs_1'));
    }

    /**
     * A session this account cannot find proves nothing: the wrong key or
     * environment would say the same about one a buyer can still pay.
     */
    public function test_a_checkout_the_provider_cannot_find_is_unknown_not_expired(): void
    {
        $gateway = $this->gateway([
            '/v1/checkout/sessions/cs_1/expire' => [self::error('invalid_request_error', 'No such checkout.session', 'resource_missing'), 404],
            '/v1/checkout/sessions/cs_1' => [self::error('invalid_request_error', 'No such checkout.session', 'resource_missing'), 404],
        ]);

        $this->expectException(GatewayOperationFailed::class);

        $gateway->expireCheckoutSession('cs_1');
    }

    public function test_an_unreachable_provider_is_unknown_not_expired(): void
    {
        $gateway = $this->gateway([
            '/v1/checkout/sessions/cs_1/expire' => [self::error('api_error', 'Down'), 500],
            '/v1/checkout/sessions/cs_1' => [self::error('api_error', 'Down'), 500],
        ]);

        $this->expectException(GatewayOperationFailed::class);

        $gateway->expireCheckoutSession('cs_1');
    }

    /** The whole report — context and every chained message — through Laravel's own reporter. */
    public function test_a_reported_provider_failure_carries_no_sensitive_text(): void
    {
        $handler = new TestHandler;
        config(['logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class], 'logging.default' => 'capture']);
        Log::channel('capture')->getLogger()->setHandlers([$handler]);

        $gateway = $this->gateway(['/v1/subscriptions/sub_1' => [
            self::error('invalid_request_error', 'Bad key sk_live_abc123SECRET for card 4242 4242 4242 4242, owner jane@example.com; secret whsec_signing123'),
            400,
        ]]);

        try {
            $gateway->retrieveSubscription('sub_1');
        } catch (GatewayOperationFailed $e) {
            report($e);
        }

        $written = (new LineFormatter(includeStacktraces: true))->format($handler->getRecords()[0]);

        $this->assertStringContainsString('billing.gateway_operation_failed', $written);
        $this->assertStringContainsString('req_trace', $written);
        foreach (['sk_live_abc123SECRET', '4242 4242 4242 4242', 'jane@example.com', 'whsec_signing123'] as $secret) {
            $this->assertStringNotContainsString($secret, $written);
        }
    }
}
