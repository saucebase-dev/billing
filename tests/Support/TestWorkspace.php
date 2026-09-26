<?php

namespace Modules\Billing\Tests\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Traits\Billable;

/**
 * A billing owner that is not a user: its own table, a ULID key, no email of
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

    public static function createTable(): void
    {
        // Where DDL commits implicitly (MySQL), the table outlives each test's rollback.
        if (Schema::hasTable('test_workspaces')) {
            return;
        }

        Schema::create('test_workspaces', function (Blueprint $table) {
            // A string, not `ulid()`: PostgreSQL makes that char(26) and pads a
            // short test ID such as '5'. Real ULIDs fill it.
            $table->string('id')->primary();
            $table->string('name');
            $table->string('billing_email');
            $table->json('members');
            $table->softDeletes();
            $table->timestamps();
        });
    }

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
