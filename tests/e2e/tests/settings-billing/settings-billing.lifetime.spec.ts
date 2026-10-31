import { expect, test } from '@e2e/fixtures';
import { SettingsBillingPage } from '../../pages/SettingsBillingPage';

test.describe('Settings Billing Lifetime', () => {
    test('shows a lifetime plan with its purchase and the payments list', async ({
        page,
        laravel,
        loginAs,
        credentials,
    }) => {
        await laravel.callFunction(
            String.raw`Modules\Billing\Tests\Support\BillingTestHelper::ownLifetime`,
            [credentials.user.email],
        );
        await loginAs(credentials.user);

        const billingPage = new SettingsBillingPage(page);
        await billingPage.goto();

        const plan = page.getByTestId('lifetime-plan');
        await expect(plan.getByTestId('lifetime-plan-kind')).toHaveText(
            'Lifetime plan',
        );
        await expect(plan.getByTestId('lifetime-plan-name')).toHaveText(
            'Lifetime E2E',
        );
        await expect(plan.getByTestId('lifetime-plan-amount')).toContainText(
            '299.00',
        );
        await expect(plan).toContainText('Paid once');
        await expect(plan).toContainText('Bought on');
        await expect(plan).toContainText('Unlimited projects');
        await expect(plan).toContainText('+1 more');

        // One purchase: the card says it all, so no table repeats it.
        await expect(page.getByTestId('payments')).toHaveCount(0);
        await expect(page.getByTestId('settings-billing-panel')).toContainText(
            'Your plan and payments',
        );

        await expect(billingPage.noSubscription).toHaveCount(0);
    });

    test('a one-off purchase has no plan card, so the payments list shows it', async ({
        page,
        laravel,
        loginAs,
        credentials,
    }) => {
        await laravel.callFunction(
            String.raw`Modules\Billing\Tests\Support\BillingTestHelper::ownLifetime`,
            [credentials.user.email, 'one_off'],
        );
        await loginAs(credentials.user);

        await new SettingsBillingPage(page).goto();

        await expect(page.getByTestId('lifetime-plan')).toHaveCount(0);
        await expect(page.getByTestId('payments')).toContainText('299.00');
    });
});
