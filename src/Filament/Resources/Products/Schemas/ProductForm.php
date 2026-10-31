<?php

namespace Modules\Billing\Filament\Resources\Products\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Models\Product;
use Saucebase\Core\Capabilities;

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
     * The stored `{features, limits}` as one row per entitlement. `EditProduct`
     * fills the form with it.
     *
     * @param  array{features?: array<string, bool>, limits?: array<string, int|null>}|null  $stored
     * @return list<array{key: string, type: string, unlimited?: bool, limit?: int|null}>
     */
    public static function entitlementRows(?array $stored): array
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

    /**
     * The edit form: the four groups as tabs, so one change doesn't mean
     * scrolling the whole plan. `CreateProduct` walks the same groups as a wizard.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->key('product-tabs')
                ->tabs(array_map(
                    fn (array $group) => Tab::make($group['label'])->icon($group['icon'])->schema($group['components']),
                    self::groups(),
                ))
                // The sections are the cards; a contained tab bar would wrap them in another.
                ->contained(false)
                ->persistTabInQueryString()
                ->columnSpanFull(),
        ]);
    }

    /** @return list<Step> */
    public static function steps(): array
    {
        return array_map(
            fn (array $group) => Step::make($group['label'])->icon($group['icon'])->schema($group['components']),
            self::groups(),
        );
    }

    /**
     * The plan's fields in four groups, each with the icon its tab and step show.
     *
     * @return list<array{label: string, icon: string, components: list<Component>}>
     */
    private static function groups(): array
    {
        return [
            ['label' => __('Basics'), 'icon' => 'heroicon-o-identification', 'components' => [
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

            ]],
            ['label' => __('Plan'), 'icon' => 'heroicon-o-sparkles', 'components' => [
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

                        TextInput::make('trial_days')
                            ->label(__('Free trial'))
                            ->suffix(__('days'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(730)
                            ->visible(fn (Get $get) => self::kindIs($get('kind'), PlanKind::Subscription))
                            ->helperText(__('Each customer gets one trial, ever. Whether payment details are collected first is a billing setting.')),

                        Repeater::make('entitlements')
                            ->label(__('Entitlements'))
                            ->helperText(__('Features the plan turns on and limits the app enforces. Changes apply to existing customers straight away.'))
                            ->schema([
                                TextInput::make('key')
                                    ->label(__('Key'))
                                    ->required()
                                    ->regex('/^\S(.*\S)?$/u')
                                    ->maxLength(255)
                                    ->distinct()
                                    ->placeholder('max_projects')
                                    // What the modules offer (core's `billing` capabilities); any other key still works.
                                    ->datalist(fn (): array => array_keys(app(Capabilities::class)->group('billing'))),
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
                            ->table([
                                TableColumn::make(__('Key')),
                                TableColumn::make(__('Type')),
                                TableColumn::make(__('Unlimited')),
                                TableColumn::make(__('Limit')),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel(__('Add entitlement'))
                            // Replaces the repeater's own mutation, which would turn the
                            // stored shape back into a list of rows.
                            ->mutateDehydratedStateUsing(fn (?array $state) => self::storedEntitlements($state)),
                    ]),

            ]],
            ['label' => __('Pricing'), 'icon' => 'heroicon-o-banknotes', 'components' => [
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
                            ->itemLabel(fn (array $state): ?string => filled($state['amount'] ?? null) && filled($state['currency'] ?? null)
                                    ? Currency::from($state['currency'])->formatPrice((int) $state['amount'], $state['interval'] ?? null, (int) ($state['interval_count'] ?? 1))
                                    : null
                            ),
                    ]),

            ]],
            ['label' => __('Display'), 'icon' => 'heroicon-o-eye', 'components' => [
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
            ]],
        ];
    }

    /** The guide, opened from the page header instead of taking a column. */
    public static function guideAction(): Action
    {
        return Action::make('guide')
            ->label(__('Guide'))
            ->icon('heroicon-o-information-circle')
            ->color('gray')
            ->slideOver()
            ->modalHeading(__('Product guide'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema([
                Section::make(__('1. Basics'))
                    ->schema([
                        Text::make(__('The name shows on the pricing page and at checkout. A plan synced from the payment provider takes its name from there: change it at the provider, then sync.')),
                        Text::make(__('The slug is the plan\'s stable ID in code and in the provider\'s metadata. SKU and slug are generated from the name when left empty.')),
                    ]),
                Section::make(__('2. Plan'))
                    ->schema([
                        Text::make(__('Kind decides what buying it means. Free: everyone\'s baseline, never sold, one at most. Subscription: recurring prices, grants its entitlements while paid (and through the grace period). Lifetime: one payment, grants its entitlements until fully refunded. One-off: one payment, grants nothing; for services the app handles itself.')),
                        Text::make(__('A lifetime plan can replace a subscription plan: buying it ends that subscription at the end of its period.')),
                        Text::make(__('Kind, slug and replaced plan are fixed once the plan is sold. To change them, create a new plan and hide this one.')),
                        Text::make(__('A trial is offered once per customer, ever.')),
                        Text::make(__('Entitlements are what the app checks. A feature is on or off (exports, packages:vendor/name). A limit is a number the app enforces (projects: 10). Leave Unlimited on for no cap; a limit no plan sets counts as 0. Keys are free text with no space at either end.')),
                        Text::make(__('Customers combine the Free plan, their subscription and every lifetime plan they own: features add up and the highest limit wins. Changes apply to existing customers straight away.')),
                    ]),
                Section::make(__('3. Pricing'))
                    ->schema([
                        Text::make(__('Amounts are in cents: 900 is 9.00. No interval means a one-time price, for Lifetime and One-off plans; Subscription plans need an interval.')),
                        Text::make(__('A price is only for sale once the payment provider knows it: use Push to Stripe. After that its amount, currency and interval are the provider\'s and locked here. To change a price, create a new one at the provider and sync.')),
                        Text::make(__('Price metadata: badge (shown next to the price, e.g. "Save 20%"), original_price (struck-through price in cents, e.g. 10800).')),
                    ]),
                Section::make(__('4. Display'))
                    ->schema([
                        Text::make(__('Active: can be bought. Visible: listed on the pricing page. Highlighted: featured, e.g. "Most popular". A plan synced from the provider is archived there, not deactivated here.')),
                        Text::make(__('Marketing features are the bullet points on the pricing card. They are sent to the provider too, for its own pages.')),
                        Text::make(__('Product metadata: badge (label on the card), tagline (line under the description), cta_label (button text), cta_url (button link instead of checkout, e.g. mailto:sales@example.com for a contact-sales plan), after_cta (small text under the button).')),
                    ]),
            ]);
    }
}
