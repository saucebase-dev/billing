import { expect, test } from '@e2e/fixtures';
import { SettingsBillingPage } from '../../pages/SettingsBillingPage';

type Credential = { email: string; password: string };

/**
 * How the panel draws a trial, a grace window and a suspension. The rules that
 * put a subscription in each state are asserted in PHP; the rows here are
 * written straight in by `BillingTestHelper::createLifecycleFixtures()`.
 */
test.describe.parallel('Settings Billing lifecycle', () => {
    let accounts: Record<'trialing' | 'pastdue' | 'suspended', Credential>;

    test.beforeEach(async ({ laravel }) => {
        accounts ??= await laravel.callFunction(
            'Modules\\Billing\\Tests\\Support\\BillingTestHelper::lifecycleCredentials',
        );
    });

    test('a trial without a card shows its end date and asks for one', async ({
        page,
        loginAs,
    }) => {
        await loginAs(accounts.trialing);

        const billingPage = new SettingsBillingPage(page);
        await billingPage.goto();

        await expect(billingPage.trial).toBeVisible();
        await expect(billingPage.addPaymentMethod).toBeVisible();
        // The card is added in the provider's portal, so that is where it leads.
        await expect(
            page.locator('a', { has: billingPage.addPaymentMethod }),
        ).toHaveAttribute('href', /\/billing\/portal$/);
        await expect(billingPage.grace).not.toBeVisible();
    });

    test('a failed payment shows the grace deadline', async ({
        page,
        loginAs,
    }) => {
        await loginAs(accounts.pastdue);

        const billingPage = new SettingsBillingPage(page);
        await billingPage.goto();

        await expect(billingPage.grace).toBeVisible();
        await expect(billingPage.suspended).not.toBeVisible();
    });

    test('a suspended subscription is still shown, marked suspended', async ({
        page,
        loginAs,
    }) => {
        await loginAs(accounts.suspended);

        const billingPage = new SettingsBillingPage(page);
        await billingPage.goto();

        await billingPage.expectPlanName('Pro');
        await expect(billingPage.suspended).toBeVisible();
        await expect(billingPage.grace).not.toBeVisible();
    });
    /** Deadline passed, sweeper runs, the customer pays: the panel follows each step. */
    test('a closed grace window suspends, and paying restores it', async ({
        page,
        laravel,
        loginAs,
    }) => {
        const customer = await laravel.callFunction<Credential>(
            'Modules\\Billing\\Tests\\Support\\BillingTestHelper::subscriberPastTheirDeadline',
        );
        await loginAs(customer);

        const billingPage = new SettingsBillingPage(page);
        await billingPage.goto();
        await expect(billingPage.grace).toBeVisible();

        await laravel.artisan('billing:end-grace-periods');
        await billingPage.reload();
        await expect(billingPage.suspended).toBeVisible();
        await expect(billingPage.grace).not.toBeVisible();

        await laravel.callFunction(
            'Modules\\Billing\\Tests\\Support\\BillingTestHelper::recover',
            [customer.email],
        );
        await billingPage.reload();
        await billingPage.expectPlanName('Pro');
        await expect(billingPage.suspended).not.toBeVisible();
        await expect(billingPage.grace).not.toBeVisible();
    });
});
