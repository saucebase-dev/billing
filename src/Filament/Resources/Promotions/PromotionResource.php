<?php

namespace Modules\Billing\Filament\Resources\Promotions;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Filament\Resources\Promotions\Pages\ManagePromotions;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Promotion;

/**
 * A provider's promotion code, attached to the prices whose cards show it. The
 * code and its terms are the provider's: created there, read here.
 */
class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 202;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage billing') ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('Promotions');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('Promotion code'))
                ->helperText(__('Create the coupon and its promotion code at the provider first, with any cap, end date and product limits. Its terms are read from there.'))
                ->required()
                ->maxLength(64)
                ->disabled(fn (?Promotion $record): bool => $record !== null),
            CheckboxList::make('price_ids')
                ->label(__('Shown on'))
                ->helperText(__('A price shows one promotion; choosing it here takes it from any other.'))
                ->options(fn (): array => Price::with('product')->where('is_active', true)->get()
                    ->mapWithKeys(fn (Price $price): array => [$price->id => ($price->product->name ?? '?').' · '.$price->currency->formatPrice($price->amount, $price->interval, $price->interval_count)])
                    ->all()),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(__('Code'))->searchable(),
                TextColumn::make('discount')
                    ->label(__('Discount'))
                    ->state(fn (Promotion $record): string => self::discountLabel($record)),
                TextColumn::make('times_redeemed')
                    ->label(__('Redeemed'))
                    ->formatStateUsing(fn (Promotion $record): string => $record->max_redemptions === null
                        ? (string) $record->times_redeemed
                        : "{$record->times_redeemed} / {$record->max_redemptions}"),
                TextColumn::make('expires_at')->label(__('Ends'))->dateTime()->placeholder(__('No end date')),
                TextColumn::make('prices_count')->label(__('Prices'))->counts('prices'),
                IconColumn::make('is_active')
                    ->label(__('Available'))
                    ->state(fn (Promotion $record): bool => Promotion::available()->whereKey($record->id)->exists())
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (Promotion $record): array => ['code' => $record->code, 'price_ids' => $record->prices()->pluck('id')->all()])
                    ->using(function (Promotion $record, array $data): Promotion {
                        self::showOn($record, $data['price_ids'] ?? []);

                        return $record;
                    }),
                DeleteAction::make(),
            ]);
    }

    /**
     * Point exactly these prices at the promotion. A price holds one, so this
     * moves it from any other.
     *
     * @param  array<int|string>  $priceIds
     */
    public static function showOn(Promotion $promotion, array $priceIds): void
    {
        Price::where('promotion_id', $promotion->id)->whereNotIn('id', $priceIds)->update(['promotion_id' => null]);
        Price::whereIn('id', $priceIds)->update(['promotion_id' => $promotion->id]);
    }

    public static function discountLabel(Promotion $promotion): string
    {
        $off = $promotion->percent_off !== null
            ? __(':percent% off', ['percent' => rtrim(rtrim(number_format($promotion->percent_off, 2), '0'), '.')])
            : __(':amount off', ['amount' => (Currency::tryFrom((string) $promotion->currency) ?? Currency::default())->formatAmount((int) $promotion->amount_off)]);

        return match ($promotion->duration) {
            'repeating' => $off.' · '.__(':months months', ['months' => $promotion->duration_in_months]),
            'forever' => $off.' · '.__('forever'),
            default => $off.' · '.__('once'),
        };
    }

    public static function getPages(): array
    {
        return ['index' => ManagePromotions::route('/')];
    }
}
