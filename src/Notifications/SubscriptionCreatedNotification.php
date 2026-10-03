<?php

namespace Modules\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Billing\Models\Subscription;

class SubscriptionCreatedNotification extends BillingNotification
{
    public function __construct(
        public Subscription $subscription,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $price = $this->subscription->price;
        $productName = $price->product->name;
        $currency = $price->currency;
        $amount = $currency->formatAmount($price->amount);
        $interval = $price->interval ?? 'month';

        return (new MailMessage)
            ->subject(__('Welcome to :product', ['product' => $productName]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Your subscription to **:product** is now active.', ['product' => $productName]))
            ->line(__('Plan: **:product** — :amount/:interval', ['product' => $productName, 'amount' => $amount, 'interval' => $interval]))
            ->action(__('Go to Dashboard'), route('settings.billing'))
            ->line(__('Thank you for subscribing!'));
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
