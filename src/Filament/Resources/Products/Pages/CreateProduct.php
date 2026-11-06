<?php

namespace Modules\Billing\Filament\Resources\Products\Pages;

use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Modules\Billing\Filament\Resources\Products\ProductResource;
use Modules\Billing\Filament\Resources\Products\Schemas\ProductForm;
use Modules\Billing\Models\Product;

class CreateProduct extends CreateRecord
{
    use HasWizard;

    protected static string $resource = ProductResource::class;

    /** @return array<Step> */
    protected function getSteps(): array
    {
        return ProductForm::steps();
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Product) {
            ProductForm::warnAboutUnknownKeys($record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [ProductForm::guideAction()];
    }
}
