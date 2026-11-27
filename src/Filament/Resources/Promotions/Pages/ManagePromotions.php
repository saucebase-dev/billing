<?php

namespace Modules\Billing\Filament\Resources\Promotions\Pages;

use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Modules\Billing\Exceptions\GatewayOperationFailedException;
use Modules\Billing\Filament\Resources\Promotions\PromotionResource;
use Modules\Billing\Models\Promotion;
use Modules\Billing\Services\PaymentGatewayManager;

class ManagePromotions extends ManageRecords
{
    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Add promotion'))
                ->using(function (array $data, CreateAction $action, PaymentGatewayManager $manager): Promotion {
                    $provider = $manager->getDefaultDriver();

                    try {
                        $found = $manager->driver($provider)->findPromotion(trim($data['code']));
                    } catch (GatewayOperationFailedException $e) {
                        report($e);
                        $found = false;
                    }

                    if (! $found) {
                        Notification::make()
                            ->title($found === null
                                ? __('The provider has no active promotion code :code.', ['code' => $data['code']])
                                : __('We could not reach the payment provider. Please try again.'))
                            ->danger()
                            ->send();

                        $action->halt();
                    }

                    // Adding a code again refreshes it rather than duplicating it.
                    $promotion = Promotion::firstOrNew(['provider' => $provider, 'provider_promotion_code_id' => $found->providerPromotionCodeId]);
                    $promotion->fillFromProvider($found)->save();

                    PromotionResource::showOn($promotion, $data['price_ids'] ?? []);

                    return $promotion;
                }),
        ];
    }
}
