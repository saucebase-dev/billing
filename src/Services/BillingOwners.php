<?php

namespace Modules\Billing\Services;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Billing\Contracts\BillingOwner;

/**
 * Which owner a user's billing is: the user, unless the app registers a
 * resolver, e.g. the current workspace with tenancy.
 *
 * `for()` hands out the owner's plan and entitlements with no check of its
 * own, so a resolver must only return an owner the user may see, taken from
 * validated context such as a membership-checked current tenant, never from
 * request input. Changing billing also needs `canManageBilling()`: `managedBy()`.
 *
 * Only the resolver is kept. The owner is resolved again on every call, so a
 * removed member is out on their next request.
 */
class BillingOwners
{
    /** @var (Closure(User): mixed)|null */
    private ?Closure $resolver = null;

    /** @param  Closure(User): mixed  $resolver */
    public function resolveUsing(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** Whose plan this user sees; null for a guest or anyone the resolver turns away. */
    public function for(?User $user): ?BillingOwner
    {
        if (! $user) {
            return null;
        }

        $owner = $this->resolver ? ($this->resolver)($user) : $user;

        return $owner instanceof BillingOwner ? $owner : null;
    }

    /** The owner whose billing this user may change. */
    public function managedBy(User $user): BillingOwner
    {
        $owner = $this->for($user);

        if (! $owner?->canManageBilling($user)) {
            throw new AuthorizationException;
        }

        return $owner;
    }
}
