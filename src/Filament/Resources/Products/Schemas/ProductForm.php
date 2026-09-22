<?php

namespace Modules\Billing\Filament\Resources\Products\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Models\Product;

class ProductForm
{
    /**
     * The provider owns what it charges and what is on sale; the app owns how it
     * is shown. Fields on the provider's side are read here and changed there.
     */
    private static function managedByGateway(?Product $record): bool
    {
        return $record?->provider_product_id !== null;
    }

    private static function priceManagedByGateway(Get $get): bool
    {
        return filled($get('provider_price_id'));
    }

    private static function kindIs(mixed $state, PlanKind $kind): bool
    {
        return ($state instanceof PlanKind ? $state : PlanKind::tryFrom((string) $state)) === $kind;
    }

    /**
     * The stored `{features, limits}` as one row per entitlement.
     *
     * @param  array{features?: array<string, bool>, limits?: array<string, int|null>}|null  $stored
     * @return list<array{key: string, type: string, unlimited?: bool, limit?: int|null}>
     */
    private static function entitlementRows(?array $stored): array
    {
        $rows = [];

        foreach (array_keys($stored['features'] ?? []) as $key) {
            $rows[] = ['key' => $key, 'type' => 'feature'];
        }

        foreach ($stored['limits'] ?? [] as $key => $limit) {
            $rows[] = ['key' => $key, 'type' => 'limit', 'unlimited' => $limit === null, 'limit' => $limit];
        }

        return $rows;
    }

    /**
     * The form's rows back in the stored shape; an empty list stores nothing.
     *
     * @param  array<array-key, array{key?: string, type?: string, unlimited?: bool, limit?: int|string|null}>|null  $rows
     * @return array{features: array<string, true>, limits: array<string, int|null>}|null
     */
    private static function storedEntitlements(?array $rows): ?array
    {
        $stored = ['features' => [], 'limits' => []];

        foreach ($rows ?? [] as $row) {
            if (($row['type'] ?? null) === 'limit') {
                $stored['limits'][$row['key']] = ($row['unlimited'] ?? false) ? null : (int) $row['limit'];
            } else {
                $stored['features'][$row['key']] = true;
            }
        }

        return $stored === ['features' => [], 'limits' => []] ? null : $stored;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(12)
            ->components([
                // Left Column - Form Fields (8 columns = 2/3 width)
                Grid::make(1)
                    ->schema([
                        Section::make(__('Basic Information'))
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('Name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->disabled(fn (?Product $record) => self::managedByGateway($record))
                                    ->helperText(fn (?Product $record) => self::managedByGateway($record) ? __('Managed at the payment provider; sync to update.') : null)
                                    ->columnSpanFull(),

                                TextInput::make('sku')
                                    ->label(__('SKU'))
                                    ->unique(ignoreRecord: true)
                                    ->alphaDash()
                                    ->helperText(__('Leave empty to auto-generate from product name'))
                                    ->maxLength(100),

                                TextInput::make('slug')
                                    ->label(__('Slug'))
                                    ->unique(ignoreRecord: true)
                                    ->alphaDash()
                                    ->maxLength(255)
                                    ->helperText(__('Leave empty to auto-generate from product name'))
                                    ->columnSpanFull(),

                                RichEditor::make('description')
                                    ->label(__('Description'))
                                    ->nullable()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Section::make(__('Plan'))
                            ->description(__('What buying it gives, and what it lets the customer do.'))
                            ->schema([
                                Select::make('kind')
                                    ->label(__('Kind'))
                                    ->options(PlanKind::class)
                                    ->default(PlanKind::Subscription)
                                    ->required()
                                    ->live()
                                    ->disabled(fn (?Product $record) => $record?->isSold() ?? false)
                                    ->helperText(fn (?Product $record) => $record?->isSold()
                                        ? __('Fixed: this plan has been sold. Create a new plan to change it.')
                                        : __('Free is everyone\'s baseline and is never sold. One-off plans grant nothing.')),

                                Select::make('replaces_product_id')
                                    ->label(__('Replaces plan'))
                                    ->helperText(__('Buying this lifetime plan ends a subscription to that plan at the end of its period.'))
                                    ->options(fn (?Product $record) => Product::where('kind', PlanKind::Subscription)
                                        ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                        ->pluck('name', 'id'))
                                    ->visible(fn (Get $get) => self::kindIs($get('kind'), PlanKind::Lifetime))
                                    ->disabled(fn (?Product $record) => $record?->isSold() ?? false),

                                Repeater::make('entitlements')
                                    ->label(__('Entitlements'))
                                    ->helperText(__('Features the plan turns on and limits the app enforces. Changes apply to existing customers straight away.'))
                                    ->schema([
                                        TextInput::make('key')
                                            ->label(__('Key'))
                                            ->required()
                                            ->regex('/^[a-z][a-z0-9_]*$/')
                                            ->distinct()
                                            ->placeholder('max_projects'),
                                        Select::make('type')
                                            ->label(__('Type'))
                                            ->options(['feature' => __('Feature'), 'limit' => __('Limit')])
                                            ->default('feature')
                                            ->required()
                                            ->live(),
                                        Toggle::make('unlimited')
                                            ->label(__('Unlimited'))
                                            ->live()
                                            ->visible(fn (Get $get) => $get('type') === 'limit'),
                                        TextInput::make('limit')
                                            ->label(__('Limit'))
                                            ->integer()
                                            ->minValue(0)
                                            ->required(fn (Get $get) => $get('type') === 'limit' && ! $get('unlimited'))
                                            ->visible(fn (Get $get) => $get('type') === 'limit' && ! $get('unlimited')),
                                    ])
                                    ->columns(4)
                                    ->defaultItems(0)
                                    ->addActionLabel(__('Add entitlement'))
                                    ->formatStateUsing(fn (?array $state) => self::entitlementRows($state))
                                    // Replaces the repeater's own mutation, which would turn the
                                    // stored shape back into a list of rows.
                                    ->mutateDehydratedStateUsing(fn (?array $state) => self::storedEntitlements($state)),
                            ]),

                        Section::make(__('Visibility'))
                            ->schema([
                                Toggle::make('is_active')
                                    ->label(__('Active'))
                                    ->helperText(fn (?Product $record) => self::managedByGateway($record)
                                        ? __('Managed at the payment provider: archive it there and sync.')
                                        : __('When disabled, the product cannot be purchased or used in the system'))
                                    ->disabled(fn (?Product $record) => self::managedByGateway($record))
                                    ->onColor('success')
                                    ->default(true),

                                Toggle::make('is_visible')
                                    ->label(__('Visible'))
                                    ->helperText(__('When enabled, the product appears in public listings and pricing pages'))
                                    ->onColor('success')
                                    ->default(true),

                                Toggle::make('is_highlighted')
                                    ->label(__('Highlighted'))
                                    ->helperText(__('When enabled, the product is featured prominently (e.g., "Most Popular" badge)'))
                                    ->onColor('success')
                                    ->default(false),
                            ])
                            ->columns(1),

                        Section::make(__('Marketing feature list'))
                            ->description(__('List the key features or benefits of this product. These will be displayed as bullet points.'))
                            ->schema([
                                Repeater::make('features')
                                    ->label(__('Features'))
                                    ->simple(
                                        TextInput::make('feature')
                                            ->label(__('Feature'))
                                            ->required()
                                            ->maxLength(255)
                                    )
                                    ->addActionLabel(__('Add Feature'))
                                    ->defaultItems(0)
                                    ->columnSpanFull(),
                            ]),

                        Section::make(__('Pricing'))
                            ->description(__('Configure pricing options for this product.'))
                            ->schema([
                                Repeater::make('prices')
                                    ->relationship()
                                    ->label(__('Prices'))
                                    ->deleteAction(fn (Action $action) => $action->hidden(
                                        fn (array $arguments, Repeater $component) => filled($component->getItemState($arguments['item'])['provider_price_id'] ?? null),
                                    ))
                                    ->schema([
                                        Grid::make(3)->schema([
                                            TextInput::make('amount')
                                                ->label(__('Amount (cents)'))
                                                ->numeric()
                                                ->required()
                                                ->disabled(fn (Get $get) => self::priceManagedByGateway($get))
                                                ->minValue(0)
                                                ->helperText(__('Enter price in cents (e.g., 900 = $9.00)')),

                                            Select::make('currency')
                                                ->label(__('Currency'))
                                                ->options(Currency::class)
                                                ->default(Currency::default())
                                                ->disabled(fn (Get $get) => self::priceManagedByGateway($get))
                                                ->required(),
                                        ]),

                                        Grid::make(3)->schema([
                                            Select::make('interval')
                                                ->label(__('Billing Interval'))
                                                ->options([
                                                    'day' => __('Daily'),
                                                    'week' => __('Weekly'),
                                                    'month' => __('Monthly'),
                                                    'year' => __('Yearly'),
                                                ])
                                                ->placeholder(__('One-time (no interval)'))
                                                ->disabled(fn (Get $get) => self::priceManagedByGateway($get)),

                                            TextInput::make('interval_count')
                                                ->label(__('Interval Count'))
                                                ->numeric()
                                                ->minValue(1)
                                                ->default(1)
                                                ->disabled(fn (Get $get) => self::priceManagedByGateway($get))
                                                ->helperText(__('e.g., 3 for quarterly')),

                                            Toggle::make('is_active')
                                                ->label(__('Active'))
                                                ->default(true)
                                                ->disabled(fn (Get $get) => self::priceManagedByGateway($get))
                                                ->onColor('success'),
                                        ]),

                                        TextInput::make('provider_price_id')
                                            ->label(__('Provider Price ID'))
                                            ->helperText(fn (Get $get) => self::priceManagedByGateway($get)
                                                ? __('Managed at the payment provider; amount, currency and interval are synced from there.')
                                                : __('Leave empty for a price the provider does not know about.'))
                                            ->disabled(fn (Get $get) => self::priceManagedByGateway($get))
                                            ->maxLength(255)
                                            ->columnSpanFull(),

                                        KeyValue::make('metadata')
                                            ->label(__('Price Metadata'))
                                            ->keyLabel(__('Key'))
                                            ->valueLabel(__('Value'))
                                            ->addActionLabel(__('Add'))
                                            ->helperText(__('Add custom data like badge text or labels (e.g., badge: "Save 20%", label: "Billed annually")'))
                                            ->columnSpanFull(),
                                    ])
                                    ->addActionLabel(__('Add Price'))
                                    ->defaultItems(0)
                                    ->columnSpanFull()
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => isset($state['amount'], $state['currency'])
                                            ? '$'.number_format($state['amount'] / 100, 2).' '.($state['currency'] instanceof Currency ? $state['currency']->value : $state['currency']).($state['interval'] ? '/'.$state['interval'] : ' one-time')
                                            : null
                                    ),
                            ]),

                        Section::make(__('Metadata'))
                            ->description(__('Add custom key-value pairs for any additional information (e.g., trial_days: 14, max_users: 100).'))
                            ->schema([
                                KeyValue::make('metadata')
                                    ->label(__('Metadata'))
                                    ->keyLabel(__('Property name'))
                                    ->valueLabel(__('Property value'))
                                    ->addActionLabel(__('Add Metadata'))
                                    ->columnSpanFull(),
                            ])
                            ->collapsible(),
                    ])
                    ->columnSpan(8),

                // Right Column - Guidance Panel (4 columns = 1/3 width)
                Grid::make(1)
                    ->schema([
                        Section::make(__('Product Creation Guide'))
                            ->schema([
                                Text::make(__('Basic Information'))
                                    ->content(__('Start by entering the product name, SKU (unique identifier), and a URL-friendly slug. The display order determines how products appear in lists (lower numbers first).')),

                                Text::make(__('Features'))
                                    ->content(__('List the key features or benefits of this product. These will be displayed as bullet points to help customers understand what they get (e.g., "Unlimited storage", "24/7 support", "Advanced analytics").')),

                                Text::make(__('Product Metadata'))
                                    ->content(__('Product metadata keys: "badge" (highlighted badge on card, e.g., "Recommended"), "tagline" (italic text below description), "cta_label" (custom button text), "cta_url" (custom button link, e.g., "mailto:sales@example.com").')),

                                Text::make(__('Price Metadata'))
                                    ->content(__('Price metadata keys: "badge" (displays next to price, e.g., "Save 20%"), "label" (text below price, e.g., "Billed annually"), "original_price" (shows strikethrough price in cents, e.g., "10800" for $108.00).')),
                            ])
                            ->icon('heroicon-o-information-circle')
                            ->iconColor('primary'),
                    ])
                    ->columnSpan(4),
            ]);
    }
}
