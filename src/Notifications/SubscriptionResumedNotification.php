<?php

namespace Modules\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Billing\Models\Subscription;

class SubscriptionResumedNotification extends BillingNotification
{
    public function __construct(
        public Subscription $subscription,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $productName = $this->subscription->price->product->name;

        return (new MailMessage)
            ->subject(__('Subscription Resumed'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Your subscription to **:product** has been resumed.', ['product' => $productName]))
            ->action(__('Manage Billing'), route('settings.billing'))
            ->line(__('Welcome back!'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->subscription->id,
        ];
    }
}
