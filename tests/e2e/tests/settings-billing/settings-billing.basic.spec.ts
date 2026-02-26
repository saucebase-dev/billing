import { test, expect } from '../../../../../Auth/tests/e2e/fixtures';
import { LoginPage } from '../../../../../Auth/tests/e2e/pages/LoginPage';
import { SettingsBillingPage } from '../../pages/SettingsBillingPage';

async function loginAs(
    page: import('@playwright/test').Page,
    user: { email: string; password: string },
) {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.login(user.email, user.password);
    await page.waitForURL('/dashboard');
}

test.describe.parallel('Settings Billing Basics', () => {
    test('redirects unauthenticated user to login', async ({ page }) => {
        await page.goto('/settings/billing');
        await expect(page).toHaveURL('/auth/login');
    });

    test('shows empty state for user without active subscription', async ({
        page,
        credentials,
    }) => {
        await loginAs(page, credentials.user);

        const billingPage = new SettingsBillingPage(page);
        await billingPage.goto();
        await billingPage.expectNoSubscription();
    });

    test('shows active subscription details', async ({ page, credentials }) => {
        await loginAs(page, credentials.subscriber);

        const billingPage = new SettingsBillingPage(page);
        await billingPage.goto();
        await billingPage.expectPlanName('Pro');
        await expect(billingPage.cancelButton).toBeVisible();
    });

    test('opens and closes cancel dialog', async ({ page, credentials }) => {
        await loginAs(page, credentials.subscriber);

        const billingPage = new SettingsBillingPage(page);
        await billingPage.goto();
        await billingPage.openCancelDialog();
        await billingPage.expectCancelDialogVisible();
        await billingPage.closeCancelDialog();
        await expect(billingPage.cancelDialogConfirm).not.toBeVisible();
    });

    test('shows resume button for pending cancellation', async ({ page, credentials }) => {
        await loginAs(page, credentials.cancelled);

        const billingPage = new SettingsBillingPage(page);
        await billingPage.goto();
        await expect(billingPage.resumeButton).toBeVisible();
        await expect(page.getByText('Cancels on')).toBeVisible();
    });
});
