<?php

namespace Modules\Billing\Tests\Unit;

use Modules\Billing\Data\Entitlements;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    public function test_a_feature_is_on_when_any_plan_grants_it(): void
    {
        $merged = Entitlements::fromArray(['features' => ['exports' => true]])
            ->merge(Entitlements::fromArray(['features' => ['sso' => true]]));

        $this->assertTrue($merged->allows('exports'));
        $this->assertTrue($merged->allows('sso'));
        $this->assertFalse($merged->allows('audit_logs'));
    }

    public function test_the_higher_limit_wins(): void
    {
        $merged = Entitlements::fromArray(['limits' => ['projects' => 3]])
            ->merge(Entitlements::fromArray(['limits' => ['projects' => 10]]));

        $this->assertSame(10, $merged->limit('projects'));
    }

    public function test_unlimited_beats_any_number(): void
    {
        $merged = Entitlements::fromArray(['limits' => ['projects' => 10]])
            ->merge(Entitlements::fromArray(['limits' => ['projects' => null]]));

        $this->assertNull($merged->limit('projects'));
    }

    public function test_a_limit_only_one_plan_sets_is_kept_as_it_is(): void
    {
        $merged = Entitlements::none()->merge(Entitlements::fromArray(['limits' => ['projects' => null, 'seats' => 5]]));

        $this->assertNull($merged->limit('projects'));
        $this->assertSame(5, $merged->limit('seats'));
    }

    /** Nothing granted is nothing allowed: a missing limit is zero, not unlimited. */
    public function test_a_limit_no_plan_mentions_is_zero(): void
    {
        $this->assertSame(0, Entitlements::fromArray([])->limit('projects'));
    }

    public function test_it_round_trips_through_its_stored_shape(): void
    {
        $stored = ['features' => ['exports' => true], 'limits' => ['projects' => 3, 'seats' => null]];

        $this->assertSame($stored, Entitlements::fromArray($stored)->toArray());
    }
}
