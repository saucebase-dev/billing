<?php

namespace Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Billing\Models\Subscription;

class SubscriptionUpdatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Subscription $subscription,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $productName = $this->subscription->price->product->name;
        $endsAt = $this->subscription->ends_at?->format('F j, Y') ?? __('the end of your billing period');

        return (new MailMessage)
            ->subject(__('Subscription Cancellation Scheduled'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Your subscription to **:product** has been scheduled for cancellation on **:date**.', ['product' => $productName, 'date' => $endsAt]))
            ->action(__('Manage Billing'), route('settings.billing'))
            ->line(__('You can resume your subscription at any time before this date.'));
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
