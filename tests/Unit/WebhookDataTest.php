<?php

namespace Modules\Billing\Tests\Unit;

use Modules\Billing\Data\Webhook\RefundData;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Exceptions\InvalidWebhookData;
use PHPUnit\Framework\TestCase;

class WebhookDataTest extends TestCase
{
    private function webhook(?WebhookEventType $type, mixed $data): WebhookData
    {
        return new WebhookData(type: $type, provider: 'fake', providerEventId: 'evt_1', data: $data);
    }

    public function test_is_matches_the_type(): void
    {
        $webhook = $this->webhook(WebhookEventType::PaymentRefunded, new RefundData(null, 'pay_1', 100, true));

        $this->assertTrue($webhook->is(WebhookEventType::PaymentRefunded));
        $this->assertFalse($webhook->is(WebhookEventType::SubscriptionUpdated));
    }

    public function test_the_data_comes_back_as_the_class_asked_for(): void
    {
        $refund = new RefundData(null, 'pay_1', 100, true);

        $this->assertSame($refund, $this->webhook(WebhookEventType::PaymentRefunded, $refund)->dataAs(RefundData::class));
    }

    /** A gateway that sends the wrong shape fails loudly, not with a null dereference later. */
    public function test_data_of_the_wrong_class_is_refused(): void
    {
        $this->expectException(InvalidWebhookData::class);

        $this->webhook(WebhookEventType::SubscriptionUpdated, new RefundData(null, 'pay_1', 100, true))
            ->dataAs(SubscriptionStateData::class);
    }

    public function test_a_recognised_type_without_data_is_refused(): void
    {
        $this->expectException(InvalidWebhookData::class);

        $this->webhook(WebhookEventType::SubscriptionUpdated, null)->dataAs(SubscriptionStateData::class);
    }
}
