<?php

namespace Modules\Billing\Filament\Resources\Products\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Filament\Actions\PushProductAction;
use Modules\Billing\Filament\Resources\Products\ProductResource;
use Modules\Billing\Filament\Resources\Products\Schemas\ProductForm;
use Modules\Billing\Models\Product;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** The save and its lock below share one transaction. */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * Entitlements become rows before the form hydrates. The repeater would
     * otherwise read `features` and `limits` as two rows, and its `unlimited`
     * toggle would write a stray `unlimited` key into each.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            ...$data,
            'entitlements' => ProductForm::entitlementRows($data['entitlements'] ?? null),
            'trial_entitlements' => ProductForm::entitlementRows($data['trial_entitlements'] ?? null),
        ];
    }

    /**
     * Takes the plan's row lock, the same one checkout takes before opening a
     * session, so a sale starting mid-edit cannot slip past the check that
     * freezes a sold plan's kind, slug and replacement.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Product::whereKey($record->getKey())->lockForUpdate()->first();

        return parent::handleRecordUpdate($record, $data);
    }

    /**
     * Saved either way: an app may check a key it never declared. The warning catches a typo.
     * It says "Saved", so it waits for the commit: a listener can still roll the save back.
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Product) {
            DB::afterCommit(fn () => ProductForm::warnAboutUnknownKeys($record));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ProductForm::guideAction(),
            PushProductAction::make(),
            ViewAction::make(),
            DeleteAction::make()
                ->requiresConfirmation()
                ->successNotificationTitle(__('Product deleted successfully')),
            ForceDeleteAction::make()
                ->requiresConfirmation(),
            RestoreAction::make()
                ->successNotificationTitle(__('Product restored successfully')),
        ];
    }
}
