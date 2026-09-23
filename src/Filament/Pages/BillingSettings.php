<?php

namespace Modules\Billing\Filament\Pages;

use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Settings\BillingSettings as Settings;
use Saucebase\Core\Filament\Pages\SettingsPage;

class BillingSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 5;

    protected static string $settings = Settings::class;

    public static function getNavigationLabel(): string
    {
        return __('Billing');
    }

    public function getTitle(): string
    {
        return __('Billing Settings');
    }

    public function form(Schema $schema): Schema
    {
        $gateways = app(PaymentGatewayManager::class);

        return $schema->columns(1)->components([
            Section::make(__('Payment provider'))
                ->description(__('Who takes the money. Credentials come from the environment, not from here.'))
                ->icon(Heroicon::OutlinedBuildingLibrary)
                ->schema([
                    Select::make('gateway')
                        ->label(__('Provider'))
                        ->options($gateways->available())
                        ->helperText($this->credentialStatus($gateways))
                        ->required()
                        ->extraAttributes(['data-testid' => 'admin-billing-gateway']),
                    Select::make('currency')
                        ->label(__('Currency'))
                        // Values, not the enum class: Filament would hand back a
                        // Currency instance, and the setting is stored as its code.
                        ->options(array_column(Currency::cases(), 'value', 'value'))
                        ->helperText(__('Used for reports and as the default for new prices. Existing prices keep their own.'))
                        ->required()
                        ->extraAttributes(['data-testid' => 'admin-billing-currency']),
                ])
                ->columns(2),

            Section::make(__('Checkout'))
                ->description(__('How buyers reach the payment page, and for how long a started checkout stays open.'))
                ->icon(Heroicon::OutlinedShoppingCart)
                ->schema([
                    Toggle::make('redirect_to_gateway')
                        ->label(__('Go straight to the payment provider'))
                        ->helperText(__('The provider asks for the email, billing address and promotion code itself. Turn this off to show the built-in checkout page first, which is worth doing only if you add fields the provider knows nothing about.'))
                        ->columnSpanFull()
                        ->extraAttributes(['data-testid' => 'admin-billing-redirect-to-gateway']),
                    TextInput::make('checkout_abandon_after_minutes')
                        ->label(__('Mark abandoned after'))
                        ->suffix(__('minutes'))
                        ->integer()
                        ->minValue(1)
                        // Abandoned comes first or never comes at all: a session
                        // already past expiry is expired, and the two-stage
                        // lifecycle collapses to one.
                        ->lt('checkout_expire_after_minutes')
                        ->required()
                        ->extraAttributes(['data-testid' => 'admin-billing-abandon-minutes']),
                    TextInput::make('checkout_expire_after_minutes')
                        ->label(__('Expire after'))
                        ->suffix(__('minutes'))
                        ->helperText(__('Stripe closes its own checkout session after 24 hours.'))
                        ->integer()
                        ->minValue(1)
                        ->gt('checkout_abandon_after_minutes')
                        ->required()
                        ->extraAttributes(['data-testid' => 'admin-billing-expire-minutes']),
                ])
                ->columns(2),

            Section::make(__('Subscriptions'))
                ->description(__('What happens after a payment fails, and how trials start.'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->schema([
                    TextInput::make('grace_period_days')
                        ->label(__('Grace period'))
                        ->suffix(__('days'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(30)
                        ->helperText(__('How long a subscription keeps working after a failed payment. Zero suspends it at once, and a grace period already running keeps the date it was given.'))
                        ->extraAttributes(['data-testid' => 'admin-billing-grace-period-days']),

                    Toggle::make('trial_requires_payment_method')
                        ->label(__('Require payment details to start a trial'))
                        ->helperText(__('With this off a trial can start without a card, and is cancelled at the end if none was added. Applies to future checkouts.'))
                        ->extraAttributes(['data-testid' => 'admin-billing-trial-requires-payment']),
                ])
                ->columns(2),
        ]);
    }

    /** One line per provider, so a choice that cannot work is visible before saving. */
    private function credentialStatus(PaymentGatewayManager $gateways): string
    {
        return collect($gateways->available())
            ->map(fn (string $label, string $slug) => $gateways->isConfigured($slug)
                ? __(':provider: credentials configured', ['provider' => $label])
                : __(':provider: credentials missing from the environment', ['provider' => $label]))
            ->implode(' · ');
    }
}
