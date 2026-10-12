<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Exceptions\GatewayOperationFailedException;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Settings\BillingSettings;
use Stripe\StripeClient;

/**
 * @method PaymentGatewayInterface driver(?string $driver = null)
 */
class PaymentGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->container->make(BillingSettings::class)->gateway;
    }

    /**
     * The providers this build can sell through: one per `createXxxDriver()`
     * method, plus anything registered with `extend()`.
     *
     * @return array<string, string> Driver slug => label
     */
    public function available(): array
    {
        $drivers = [];

        foreach (get_class_methods($this) as $method) {
            if (preg_match('/^create(\w+)Driver$/', $method, $match)) {
                $drivers[Str::snake($match[1])] = $match[1];
            }
        }

        foreach (array_keys($this->customCreators) as $slug) {
            $drivers[$slug] = Str::headline($slug);
        }

        return $drivers;
    }

    /** Whether the credentials the driver needs are present in the environment. */
    public function isConfigured(string $driver): bool
    {
        return match ($driver) {
            'stripe' => filled($this->config->get('services.stripe.secret_key'))
                && filled($this->config->get('services.stripe.webhook_secret')),
            default => isset($this->customCreators[$driver]),
        };
    }

    public function createStripeDriver(): StripeGateway
    {
        $secretKey = $this->config->get('services.stripe.secret_key');

        // Stripe's client refuses a missing key with an error of its own, which would
        // reach a buyer as a server error instead of a checkout they can retry.
        if (blank($secretKey)) {
            throw new GatewayOperationFailedException('stripe', 'connect: STRIPE_SECRET_KEY is not set');
        }

        return new StripeGateway(new StripeClient($secretKey));
    }
}
