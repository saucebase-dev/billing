import { expect, test } from '@e2e/fixtures';

/**
 * The pricing page is the module's shop window. Which plans it lists and in what
 * order is asserted in PHP; this is about what a visitor actually sees.
 */
test.describe.parallel('Pricing page', () => {
    test('lists the plans an admin has made visible', async ({ page }) => {
        await page.goto('/pricing');

        await expect(page.getByTestId('product-card-starter')).toBeVisible();
        await expect(page.getByTestId('product-card-pro')).toBeVisible();
    });

    test('keeps a plan nobody has reviewed off the page', async ({ page }) => {
        await page.goto('/pricing');

        await expect(page.getByTestId('product-card-pro')).toBeVisible();
        await expect(
            page.getByTestId('product-card-unreviewed'),
        ).not.toBeVisible();
    });

    test('shows the plans in the order the admin arranged', async ({
        page,
    }) => {
        await page.goto('/pricing');
        // evaluateAll does not wait for anything to exist, unlike expect().
        await page.getByTestId('product-card-pro').waitFor();

        const slugs = await page
            .locator('[data-testid^="product-card-"]')
            .evaluateAll((cards) =>
                cards.map((card) =>
                    (card.getAttribute('data-testid') ?? '').replace(
                        'product-card-',
                        '',
                    ),
                ),
            );

        expect(slugs.indexOf('starter')).toBeLessThan(slugs.indexOf('pro'));
    });

    test('marks the highlighted plan', async ({ page }) => {
        await page.goto('/pricing');

        await expect(
            page.getByTestId('product-card-pro').getByTestId('product-badge'),
        ).toBeVisible();
        await expect(
            page
                .getByTestId('product-card-starter')
                .getByTestId('product-badge'),
        ).not.toBeVisible();
    });

    test('sends a guest who picks a plan to register', async ({ page }) => {
        await page.goto('/pricing');

        await page
            .locator(
                '[data-testid="product-card-pro"] [data-testid="get-started-button"]',
            )
            .click();

        // The click posts and the server redirects; under a full parallel run
        // that round trip outlasts the default assertion timeout.
        await expect(page).toHaveURL(/\/auth\/register/, { timeout: 15_000 });
    });
});
