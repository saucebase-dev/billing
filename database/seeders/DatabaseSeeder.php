<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    /**
     * The billing admin area's permission, granted to nobody: the app's roles seeder decides who gets
     * it, and `admin` passes every check anyway.
     */
    public function run(): void
    {
        Permission::findOrCreate('manage billing');
    }
}
