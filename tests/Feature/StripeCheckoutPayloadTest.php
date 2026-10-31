<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\Gateways\StripeGateway;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * What a checkout asks Stripe for. Every other checkout test stubs the gateway, so
 * this is the only place a wrong mode, customer or price would show.
 */
class StripeCheckoutPayloadTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        ApiRequestor::setHttpClient(new class($this->sent) implements ClientInterface
        {
            public function __construct(public array &$sent) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->sent = $params;

                return [json_encode(['id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.test/cs_test']), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    public function test_a_recurring_price_opens_a_subscription_for_the_customer(): void
    {
        $this->checkout(Price::factory()->create(['provider_price_id' => 'price_monthly', 'interval' => 'month']));

        $this->assertSame('subscription', $this->sent['mode']);
        $this->assertSame('cus_123', $this->sent['customer']);
        $this->assertSame([['price' => 'price_monthly', 'quantity' => 1]], $this->sent['line_items']);
        $this->assertSame('https://app.test/ok', $this->sent['success_url']);
        $this->assertSame('https://app.test/no', $this->sent['cancel_url']);
    }

    public function test_a_one_off_price_opens_a_payment(): void
    {
        $this->checkout(Price::factory()->create(['provider_price_id' => 'price_once', 'interval' => null]));

        $this->assertSame('payment', $this->sent['mode']);
        $this->assertSame([['price' => 'price_once', 'quantity' => 1]], $this->sent['line_items']);
    }

    public function test_the_session_is_returned_as_the_provider_sent_it(): void
    {
        $result = $this->checkout(Price::factory()->create(['interval' => 'month']));

        $this->assertSame('cs_test', $result->sessionId);
        $this->assertSame('https://checkout.test/cs_test', $result->url);
    }

    private function checkout(Price $price): CheckoutResultData
    {
        $gateway = new StripeGateway(new StripeClient(['api_key' => 'sk_test_stub', 'max_network_retries' => 0]));

        return $gateway->createCheckoutSession(new CheckoutData(
            customer: Customer::factory()->create(['provider_customer_id' => 'cus_123']),
            price: $price,
            successUrl: 'https://app.test/ok',
            cancelUrl: 'https://app.test/no',
        ));
    }
}
