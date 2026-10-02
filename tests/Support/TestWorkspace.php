<?php

namespace Modules\Billing\Tests\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Traits\Billable;

/**
 * A billing owner that is not a user: its own table (`tests/Support/migrations`), a ULID key, no email of
 * its own. Members are `[user id => 'manager'|'member']`.
 *
 * @property string $id
 * @property string $name
 * @property string $billing_email
 * @property array<int|string, string> $members
 */
class TestWorkspace extends Model implements BillingOwner
{
    use Billable;
    use HasUlids;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'test_workspaces';

    protected $guarded = [];

    protected $casts = ['members' => 'array'];

    public function isMember(User $user): bool
    {
        return isset($this->members[$user->id]);
    }

    public function canManageBilling(User $user): bool
    {
        return ($this->members[$user->id] ?? null) === 'manager';
    }

    public function routeNotificationForMail(): string
    {
        return $this->billing_email;
    }
}
