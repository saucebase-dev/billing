<?php

namespace Modules\Billing\Data;

/**
 * What a plan lets its owner do: features that are on, and limits the app
 * enforces itself. The billing module only answers "is it allowed" and "how
 * many"; counting projects or seats stays the app's job.
 *
 * Plans combine generously: a feature any plan grants is on, and the higher
 * limit wins, with null meaning unlimited.
 */
final class Entitlements
{
    /**
     * @param  array<string, true>  $features
     * @param  array<string, int|null>  $limits
     */
    private function __construct(
        private readonly array $features,
        private readonly array $limits,
    ) {}

    /**
     * @param  array{features?: array<string, bool>, limits?: array<string, int|null>}|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        return new self(
            array_filter($stored['features'] ?? [], fn (bool $on) => $on),
            $stored['limits'] ?? [],
        );
    }

    public static function none(): self
    {
        return new self([], []);
    }

    public function merge(self $other): self
    {
        $limits = $this->limits;

        foreach ($other->limits as $key => $limit) {
            if (! array_key_exists($key, $limits)) {
                $limits[$key] = $limit;
            } elseif ($limits[$key] !== null) {
                $limits[$key] = $limit === null ? null : max($limits[$key], $limit);
            }
        }

        return new self($this->features + $other->features, $limits);
    }

    public function allows(string $feature): bool
    {
        return isset($this->features[$feature]);
    }

    /** Null is unlimited; a limit no plan mentions is zero. */
    public function limit(string $key): ?int
    {
        return array_key_exists($key, $this->limits) ? $this->limits[$key] : 0;
    }

    /**
     * @return array{features: array<string, true>, limits: array<string, int|null>}
     */
    public function toArray(): array
    {
        return ['features' => $this->features, 'limits' => $this->limits];
    }
}
