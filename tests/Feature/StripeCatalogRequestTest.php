<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\Gateways\StripeGateway;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * What actually goes over the wire to Stripe.
 *
 * The gateway is the one place the app's shape is translated into Stripe's, and
 * a mistake there is invisible locally — the rows look right and the provider
 * quietly has something else. Stubbing Stripe's own HTTP client is the only way
 * to see the request it would have sent.
 */
class StripeCatalogRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{method: string, url: string, params: array<string, mixed>}> */
    private array $requests = [];

    private function gateway(string $responseBody = '{"id": "prod_new"}'): StripeGateway
    {
        $client = new class($this->requests, $responseBody) implements ClientInterface
        {
            public function __construct(private array &$requests, private string $body) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

                return [$this->body, 200, []];
            }
        };

        ApiRequestor::setHttpClient($client);

        return new StripeGateway(new StripeClient('sk_test_stub'));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_a_products_features_are_sent_as_stripe_marketing_features(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => 'prod_known',
            'features' => ['10 projects', 'Priority support'],
        ]);

        $this->gateway()->pushProductFeatures($product);

        $this->assertSame(
            [['name' => '10 projects'], ['name' => 'Priority support']],
            $this->requests[0]['params']['marketing_features'],
        );
    }

    /** Stripe rejects the whole product past its limits, so the list is trimmed, not risked. */
    public function test_the_feature_list_is_trimmed_to_what_stripe_accepts(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => 'prod_known',
            'features' => [...array_fill(0, 20, 'A feature'), str_repeat('x', 100)],
        ]);

        $this->gateway()->pushProductFeatures($product);

        $sent = $this->requests[0]['params']['marketing_features'];

        $this->assertCount(15, $sent);
        $this->assertLessThanOrEqual(80, mb_strlen($sent[0]['name']));
    }

    /** An empty list has to be explicit, or Stripe leaves the old one in place. */
    public function test_clearing_the_features_sends_an_empty_list(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => 'prod_known',
            'features' => [],
        ]);

        $this->gateway()->pushProductFeatures($product);

        $this->assertSame([], $this->requests[0]['params']['marketing_features']);
    }

    /** Blank entries an admin left behind would show as empty bullets on Stripe. */
    public function test_blank_features_are_dropped(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => 'prod_known',
            'features' => ['Real', '   ', ''],
        ]);

        $this->gateway()->pushProductFeatures($product);

        $this->assertSame([['name' => 'Real']], $this->requests[0]['params']['marketing_features']);
    }
}
