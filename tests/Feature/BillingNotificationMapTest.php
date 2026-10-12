<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Modules\Billing\Events\BillingEvent;
use Modules\Billing\Events\GraceStarted;
use Modules\Billing\Events\PaymentSucceeded;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Notifications\GraceStartedNotification;
use Tests\TestCase;

/** `config('billing.notifications')` decides which mail each event sends. */
class BillingNotificationMapTest extends TestCase
{
    use RefreshDatabase;

    private function graceStarted(): void
    {
        $customer = Customer::factory()->for($this->createUser(), 'owner')->create();

        GraceStarted::dispatch(Subscription::factory()->create(['customer_id' => $customer->id]));
    }

    public function test_every_mapped_event_is_a_billing_event(): void
    {
        foreach (array_keys(config('billing.notifications')) as $event) {
            $this->assertTrue(is_subclass_of($event, BillingEvent::class), $event);
        }

        $this->assertArrayHasKey(PaymentSucceeded::class, config('billing.notifications'));
    }

    public function test_an_unmapped_event_sends_nothing(): void
    {
        Notification::fake();
        config()->set('billing.notifications.'.GraceStarted::class, []);

        $this->graceStarted();

        Notification::assertNothingSent();
    }

    public function test_a_mapped_subclass_replaces_the_mail(): void
    {
        Notification::fake();
        config()->set('billing.notifications.'.GraceStarted::class, [OurGraceMail::class]);

        $this->graceStarted();

        Notification::assertSentTimes(OurGraceMail::class, 1);
        Notification::assertNotSentTo($this->createUser(), GraceStartedNotification::class);
    }

    public function test_a_class_that_is_not_a_notification_is_refused(): void
    {
        config()->set('billing.notifications.'.GraceStarted::class, [\stdClass::class]);

        $this->expectException(InvalidArgumentException::class);

        $this->graceStarted();
    }

    /** One job per mail, so a failed send retries only itself and never mails twice. */
    public function test_each_mail_is_queued_on_its_own(): void
    {
        Queue::fake();

        $this->graceStarted();

        Queue::assertPushed(SendQueuedNotifications::class, 1);
        Queue::assertNotPushed(CallQueuedListener::class);
    }
}

class OurGraceMail extends GraceStartedNotification {}
