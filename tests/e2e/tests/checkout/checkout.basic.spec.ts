import { expect, test } from '@e2e/fixtures';

/**
 * Who may start a checkout. Everything that depends on the global
 * `redirect_to_gateway` setting lives in `checkout.flow.spec.ts`, which owns it:
 * the runner is `fullyParallel` across files, so a second file reading or
 * writing that setting races with the one that switches it.
 */
test.describe('Checkout Basics', () => {
    test('redirects a guest to register', async ({ page }) => {
        await page.goto(
            '/billing/checkout/00000000-0000-0000-0000-000000000000',
        );

        await expect(page).toHaveURL('/auth/register');
    });
});
