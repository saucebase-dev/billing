<?php

namespace Modules\Billing\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Services\PaymentGatewayManager;

/** Save the payment method a provider reference points at, as the default unless told otherwise. */
class SyncPaymentMethod
{
    public function __construct(
        private PaymentGatewayManager $manager,
    ) {}

    public function handle(Customer $customer, string $reference, string $provider, bool $makeDefault = true): ?PaymentMethod
    {
        $data = $this->manager->driver($provider)->resolvePaymentMethod($reference);

        if (! $data) {
            return null;
        }

        $match = ['provider' => $provider, 'provider_payment_method_id' => $data->providerPaymentMethodId];

        return DB::transaction(function () use ($customer, $data, $match, $makeDefault) {
            // Several events carry the same card at once (attached, customer
            // updated, checkout completed), so another webhook may insert it
            // first. The inner transaction is a savepoint: losing the race
            // rolls back only the insert, and the locking read sees the winner.
            try {
                $method = PaymentMethod::where($match)->first()
                    ?? DB::transaction(fn () => PaymentMethod::create([
                        ...$match,
                        'customer_id' => $customer->id,
                        'type' => $data->type,
                        'details' => $data->details->toArray(),
                        'is_default' => false,
                    ]));
            } catch (UniqueConstraintViolationException) {
                $method = PaymentMethod::where($match)->lockForUpdate()->firstOrFail();
            }

            if ($makeDefault && ! $method->is_default) {
                PaymentMethod::where('customer_id', $customer->id)
                    ->where('is_default', true)
                    ->lockForUpdate()
                    ->update(['is_default' => false]);
                $method->update(['is_default' => true]);
            }

            return $method;
        });
    }
}
