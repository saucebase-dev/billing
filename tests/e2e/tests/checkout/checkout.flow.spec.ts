import { expect, test } from '@e2e/fixtures';
import { CheckoutPage } from '../../pages/CheckoutPage';
import { SettingsBillingPage } from '../../pages/SettingsBillingPage';
// The auth module owns the registration form; reusing its page object keeps this
// spec from breaking every time that form is restyled.
import { RegisterPage } from '@modules/auth/tests/e2e/pages/RegisterPage';

const REDIRECT_SETTING = 'redirect_to_gateway';
const COMPLETE_CHECKOUT =
    'Modules\\Billing\\Tests\\Support\\BillingTestHelper::completeCheckout';

/**
 * Picking a plan through to holding a subscription.
 *
 * Only the hand-off to the provider is stood in for: the browser cannot reach
 * Stripe, so the checkout is finished the way Stripe's webhook would finish it.
 * Everything either side of that is the real application.
 */
test.describe('Buying a plan', () => {
    // The module's own checkout page is the one with something to assert, and
    // the setting is global, so these run in order with it switched once.
    test.describe.configure({ mode: 'serial' });

    const setRedirect = (laravel: { query: Function }, value: string) =>
        laravel.query(
            'UPDATE settings SET payload = ? WHERE `group` = ? AND name = ?',
            [value, 'billing', REDIRECT_SETTING],
        );

    test.beforeAll(async ({ laravel }) => {
        await setRedirect(laravel, 'false');
    });

    test.afterAll(async ({ laravel }) => {
        await setRedirect(laravel, 'true');
    });

    test('a signed-in buyer goes from the pricing page to a subscription', async ({
        page,
        laravel,
        loginAs,
        credentials,
    }) => {
        await loginAs(credentials.user);

        const billing = new SettingsBillingPage(page);
        await billing.goto();
        await billing.expectNoSubscription();

        const checkout = new CheckoutPage(page);
        await checkout.startFromPricing('pro');
        await checkout.expectProductName('Pro');
        await checkout.expectFormVisible();

        await laravel.callFunction(COMPLETE_CHECKOUT, [credentials.user.email]);

        await billing.goto();
        await billing.expectPlanName('Pro');
        await expect(billing.cancelButton).toBeVisible();
    });

    test('a buyer who starts a free trial is on a trial', async ({
        page,
        laravel,
        loginAs,
        credentials,
    }) => {
        await loginAs(credentials.user);

        const checkout = new CheckoutPage(page);
        await checkout.startFromPricing('trial');
        await checkout.expectProductName('Trial');

        await laravel.callFunction(COMPLETE_CHECKOUT, [credentials.user.email]);

        const billing = new SettingsBillingPage(page);
        await billing.goto();
        await billing.expectPlanName('Trial');
        await expect(billing.trial).toBeVisible();
    });

    /**
     * The e2e price is unknown to Stripe, so a real hand-off fails there before
     * anything is created. The buyer stays on this checkout, and trying again
     * resends it rather than starting another.
     */
    test('a failed hand-off keeps the checkout and offers to try again', async ({
        page,
        laravel,
        loginAs,
    }) => {
        const buyer = await laravel.callFunction<{
            email: string;
            password: string;
        }>('Modules\\Billing\\Tests\\Support\\BillingTestHelper::knownBuyer');
        await loginAs(buyer);
        await setRedirect(laravel, 'true');

        try {
            await page.goto('/pricing');
            await page
                .locator(
                    '[data-testid="product-card-pro"] [data-testid="get-started-button"]',
                )
                .click();

            await expect(
                page.getByTestId('checkout-handoff-failed'),
            ).toBeVisible({
                timeout: 15_000,
            });

            await page.getByTestId('checkout-retry').click();
            await expect(
                page.getByTestId('checkout-handoff-failed'),
            ).toBeVisible({
                timeout: 15_000,
            });

            const sessions = await laravel.select(
                'SELECT COUNT(*) AS n FROM checkout_sessions cs JOIN customers c ON c.id = cs.customer_id JOIN users u ON u.id = c.user_id WHERE u.email = :email',
                { email: buyer.email },
            );
            expect(Number(sessions[0].n)).toBe(1);
        } finally {
            await setRedirect(laravel, 'false');
        }
    });

    test('shows the plan being bought', async ({
        page,
        loginAs,
        credentials,
    }) => {
        await loginAs(credentials.admin);

        const checkout = new CheckoutPage(page);
        await checkout.startFromPricing('pro');

        await expect(checkout.orderSummary).toBeVisible();
        await checkout.expectProductName('Pro');
        await expect(checkout.total).toBeVisible();
    });

    test('asks for an email and hides the coupon field until it is wanted', async ({
        page,
        loginAs,
        credentials,
    }) => {
        await loginAs(credentials.admin);

        const checkout = new CheckoutPage(page);
        await checkout.startFromPricing('pro');

        await checkout.expectFormVisible();
        await expect(checkout.couponInput).not.toBeVisible();

        await checkout.couponToggle.click();

        await expect(checkout.couponInput).toBeVisible();
    });

    /**
     * Someone who picks a plan before they have an account has to arrive back at
     * the checkout they chose, not at a dashboard with nothing bought.
     */
    test('a guest who registers mid-purchase lands back on their checkout', async ({
        page,
    }) => {
        await page.goto('/pricing');
        await page
            .locator(
                '[data-testid="product-card-pro"] [data-testid="get-started-button"]',
            )
            .click();

        await expect(page).toHaveURL(/\/auth\/register/);

        const register = new RegisterPage(page);
        await register.register(
            'New Buyer',
            `buyer-${Date.now()}@example.com`,
            'password',
        );

        await page.waitForURL(/\/billing\/checkout\/.+/);

        const checkout = new CheckoutPage(page);
        await checkout.expectProductName('Pro');
    });
});
