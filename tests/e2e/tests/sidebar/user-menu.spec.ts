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

    // A user with nothing bought is on the app's Free plan, if it has one (a template may
    // ship one); with none, there is no plan to name and the menu falls back to the email.
    test('names the Free plan, or the email when there is none', async ({
        page,
        laravel,
        loginAs,
        credentials,
    }) => {
        const [free] = await laravel.select(
            "SELECT name FROM products WHERE kind = 'free' AND deleted_at IS NULL",
        );

        await loginAs(credentials.user);
        await page.goto('/dashboard');

        await expect(page.getByTestId('user-menu-plan')).toHaveText(
            (free?.name as string | undefined) ?? credentials.user.email,
        );
    });
});
