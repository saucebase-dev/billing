<?php

namespace Modules\Billing\Exceptions;

/**
 * A call to the payment provider failed at the provider or on the way there.
 * Not a programming error: those are never wrapped in this.
 */
class GatewayOperationFailed extends BillingException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $operation,
        ?ProviderError $previous = null,
        public readonly ?string $providerResourceId = null,
    ) {
        parent::__construct("The {$provider} gateway failed to {$operation}.", previous: $previous);
    }

    public function id(): string
    {
        return 'billing.gateway_operation_failed';
    }

    protected function details(): array
    {
        $error = $this->getPrevious();

        return [
            'provider' => $this->provider,
            'operation' => $this->operation,
            'provider_resource_id' => $this->providerResourceId,
            'provider_request_id' => $error instanceof ProviderError ? $error->requestId : null,
            'provider_code' => $error instanceof ProviderError ? $error->providerCode : null,
            'http_status' => $error instanceof ProviderError ? $error->httpStatus : null,
        ];
    }
}
