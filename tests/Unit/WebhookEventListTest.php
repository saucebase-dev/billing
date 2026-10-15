<?php

namespace Modules\Billing\Tests\Unit;

use Modules\Billing\Services\Gateways\StripeGateway;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * The README lists the events to subscribe to on the production webhook. An event the
 * gateway handles but the list leaves out never reaches production, and nothing fails.
 */
class WebhookEventListTest extends TestCase
{
    public function test_the_readme_lists_exactly_the_events_the_gateway_handles(): void
    {
        $handled = array_keys((new ReflectionClassConstant(StripeGateway::class, 'EVENT_MAP'))->getValue());

        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
        preg_match('/```\n(checkout\.session\.completed\n.*?)```/s', $readme, $block);
        $listed = array_filter(explode("\n", $block[1] ?? ''));

        $this->assertEqualsCanonicalizing($handled, $listed);
    }
}
