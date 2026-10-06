<?php

namespace Modules\Billing\Tests\Support\Traits;

use Modules\Billing\Services\BillingOwners;

/**
 * Billing's tests check billing's own rules, where the user pays. An app can change who
 * pays (tenancy's workspace-billing patch makes it the current workspace), so each test
 * starts from the default instead of whatever the app registered. The patched setup has
 * its own tests, in tenancy's `WorkspaceBillingTest`.
 */
trait BillsTheUser
{
    protected function setUpBillsTheUser(): void
    {
        $this->app->forgetInstance(BillingOwners::class);
    }
}
