<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * `php artisan optimize` runs `view:cache`, which fails on a registered view folder that
 * does not exist, and stops before caching the modules.
 */
class ViewPathsTest extends TestCase
{
    public function test_every_view_folder_billing_registers_exists(): void
    {
        $paths = View::getFinder()->getHints()['billing'] ?? [];

        foreach ($paths as $path) {
            $this->assertDirectoryExists($path);
        }

        $this->addToAssertionCount(1);
    }
}
