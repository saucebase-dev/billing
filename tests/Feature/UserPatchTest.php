<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Other modules patch the same User model, in any order, so this patch may only add
 * lines: an edited line is one another module's patch no longer finds. And it must
 * apply to the User core ships.
 */
class UserPatchTest extends TestCase
{
    private const PATCH = __DIR__.'/../../patches/user.patch';

    public function test_it_only_adds_lines(): void
    {
        $removed = preg_grep('/^-(?!--)/', file(self::PATCH, FILE_IGNORE_NEW_LINES) ?: []);

        $this->assertSame([], array_values($removed));
    }

    public function test_it_applies_to_the_user_core_ships(): void
    {
        $app = sys_get_temp_dir().'/user-patch-'.uniqid();
        File::ensureDirectoryExists($app.'/app/Models');
        File::copy(base_path('vendor/saucebase/core/stubs/app/app/Models/User.php'), $app.'/app/Models/User.php');

        try {
            $apply = new Process(['git', 'apply', '--check', realpath(self::PATCH)], $app);

            $this->assertSame(0, $apply->run(), $apply->getErrorOutput());
        } finally {
            File::deleteDirectory($app);
        }
    }
}
