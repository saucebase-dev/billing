<?php

namespace Modules\Billing\Tests\Feature;

use Modules\Billing\Exceptions\GatewayOperationFailedException;
use Modules\Billing\Services\PaymentGatewayManager;
use Tests\TestCase;

class PaymentGatewayManagerTest extends TestCase
{
    /** A buyer then sees "could not reach the payment provider", not a server error. */
    public function test_stripe_without_a_secret_key_fails_as_an_unreachable_provider(): void
    {
        config(['services.stripe.secret_key' => null]);

        $this->expectException(GatewayOperationFailedException::class);

        app(PaymentGatewayManager::class)->driver('stripe');
    }
}
