<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\BillingOwners;
use Saucebase\Core\Facades\Navigation;
use Saucebase\Core\Navigation\Section;

/*
|--------------------------------------------------------------------------
| Billing Module Navigation
|--------------------------------------------------------------------------
|
| Define Billing module navigation items here.
| These items will be loaded automatically when the module is enabled.
|
*/

// Landing Page Navigation
Navigation::addWhen(
    fn () => Product::displayable()->exists(),
    'Pricing',
    fn () => route('billing.plans'),
    function (Section $section) {
        $section->attributes([
            'group' => 'landing',
            'slug' => 'pricing',
            'order' => 1,
        ]);
    }
);

// User menu - Upgrade
Navigation::addWhen(
    // The plan of whoever pays for this user: a workspace member is not asked to
    // upgrade, and someone with no owner at all has nothing to upgrade.
    function () {
        $owner = Auth::user() instanceof User ? app(BillingOwners::class)->for(Auth::user()) : null;

        return $owner !== null && ! $owner->hasPaidPlan();
    },
    'Upgrade',
    fn () => route('billing.plans'),
    function (Section $section) {
        $section->attributes([
            'group' => 'user',
            'slug' => 'upgrade',
            'icon' => 'upgrade',
            'order' => 0,
            'class' => 'text-yellow-600 hover:text-yellow-700 dark:hover:text-yellow-400',
        ]);
    }
);
