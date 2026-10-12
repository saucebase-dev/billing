import { expect, test } from '@e2e/fixtures';

/** Billing puts the plan under the user's first name in the sidebar's user menu. */
test.describe.parallel('Sidebar user menu', () => {
    test('shows a subscriber their first name and plan', async ({
        page,
        loginAs,
        credentials,
    }) => {
        await loginAs(credentials.subscriber);
        await page.goto('/dashboard');

        await expect(page.getByTestId('user-menu-name')).toHaveText(
            'Subscriber',
        );
        await expect(page.getByTestId('user-menu-plan')).toHaveText('Pro');
    });

    test('falls back to the email when there is no plan to name', async ({
        page,
        loginAs,
        credentials,
    }) => {
        await loginAs(credentials.user);
        await page.goto('/dashboard');

        await expect(page.getByTestId('user-menu-plan')).toHaveText(
            credentials.user.email,
        );
    });
});
