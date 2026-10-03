<?php

namespace Modules\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Billing\Models\Subscription;

class AccessSuspendedNotification extends BillingNotification
{
    public function __construct(
        public Subscription $subscription,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $productName = $this->subscription->price?->plan->name ?? __('your plan');

        return (new MailMessage)
            ->subject(__('Your subscription is suspended'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Your subscription to **:product** is suspended because the payment never went through.', ['product' => $productName]))
            ->line(__('Update your payment details and it starts again where it left off.'))
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
