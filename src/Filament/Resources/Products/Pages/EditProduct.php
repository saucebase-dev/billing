<?php

namespace Modules\Billing\Filament\Resources\Products\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Billing\Filament\Actions\PushProductAction;
use Modules\Billing\Filament\Resources\Products\ProductResource;
use Modules\Billing\Models\Product;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** The save and its lock below share one transaction. */
    protected ?bool $hasDatabaseTransactions = true;

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

    protected function getHeaderActions(): array
    {
        return [
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
