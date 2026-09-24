<?php

namespace Modules\Billing\Exceptions;

/**
 * A billing failure worth diagnosing. `id()` is stable across releases and
 * wording changes, so logs and alerts can match on it; `context()` is what the
 * report carries, built only from the fields each exception names.
 */
abstract class BillingException extends \RuntimeException
{
    abstract public function id(): string;

    /** @return array<string, scalar|null> */
    protected function details(): array
    {
        return [];
    }

    /**
     * Merged into every report by Laravel's exception handler.
     *
     * @return array<string, scalar|null>
     */
    final public function context(): array
    {
        return ['billing_error_id' => $this->id(), ...array_filter($this->details(), fn ($value) => $value !== null)];
    }
}
