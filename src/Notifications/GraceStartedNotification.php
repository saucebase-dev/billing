<?php

namespace Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Billing\Models\Subscription;

class GraceStartedNotification extends Notification
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
        $productName = $this->subscription->price?->plan->name ?? __('your plan');

        $deadline = $this->subscription->grace_ends_at?->format('F j, Y') ?? __('shortly');

        return (new MailMessage)
            ->subject(__('Your payment did not go through'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('We could not charge your card for **:product**.', ['product' => $productName]))
            ->line(__('Update your payment details by **:date** to keep your subscription.', ['date' => $deadline]))
            ->action(__('Update payment details'), route('billing.portal'));
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
