<?php

namespace Modules\Billing\Exceptions;

/**
 * What a provider's SDK threw, kept for diagnosis without its raw text: the
 * report writes every previous exception's message, and a provider message can
 * echo back what it was sent.
 */
class ProviderError extends BillingException
{
    public function __construct(
        public readonly string $sdkClass,
        string $message,
        public readonly ?string $providerCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct(self::redact($message));
    }

    public function id(): string
    {
        return 'billing.provider_error';
    }

    protected function details(): array
    {
        return [
            'sdk_class' => $this->sdkClass,
            'provider_code' => $this->providerCode,
            'http_status' => $this->httpStatus,
            'provider_request_id' => $this->requestId,
        ];
    }

    /** Keys, card-number-like digit runs and email addresses, replaced. */
    public static function redact(string $text): string
    {
        return preg_replace(
            [
                '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]+/',
                '/\bwhsec_[A-Za-z0-9]+/',
                '/\b(?:\d[ -]?){12,19}\b/',
                '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            ],
            ['[redacted key]', '[redacted secret]', '[redacted number]', '[redacted email]'],
            $text,
        ) ?? '[redacted]';
    }
}
