<?php

namespace Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Billing\Models\Subscription;

class TrialEndingNotification extends Notification
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

        $endsAt = $this->subscription->trial_ends_at?->format('F j, Y') ?? __('in a few days');

        $mail = (new MailMessage)
            ->subject(__('Your trial ends soon'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Your free trial of **:product** ends on **:date**.', ['product' => $productName, 'date' => $endsAt]));

        // With nothing to charge, the provider cancels when the trial ends.
        if (! $this->subscription->hasPaymentMethod()) {
            return $mail
                ->line(__('Add your payment details before then to keep your subscription.'))
                ->action(__('Add payment details'), route('billing.portal'));
        }

        return $mail
            ->line(__('Nothing to do if you are staying: your subscription simply begins.'))
            ->action(__('Manage billing'), route('settings.billing'));
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
