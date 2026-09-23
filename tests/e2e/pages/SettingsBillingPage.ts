import { expect, type Locator, type Page } from '@playwright/test';

export class SettingsBillingPage {
    readonly page: Page;
    readonly subscriptionSection: Locator;
    readonly planName: Locator;
    readonly cancelButton: Locator;
    readonly resumeButton: Locator;
    readonly cancelDialog: Locator;
    readonly cancelDialogConfirm: Locator;
    readonly cancelDialogCancel: Locator;
    readonly noSubscription: Locator;
    readonly panel: Locator;
    readonly trial: Locator;
    readonly grace: Locator;
    readonly suspended: Locator;
    readonly addPaymentMethod: Locator;

    constructor(page: Page) {
        this.page = page;
        this.subscriptionSection = page.getByTestId('subscription-section');
        this.planName = page.getByTestId('plan-name');
        this.cancelButton = page.getByTestId('cancel-button');
        this.resumeButton = page.getByTestId('resume-button');
        this.cancelDialog = page.getByTestId('confirm-dialog');
        this.cancelDialogConfirm = page.getByTestId('confirm-dialog-confirm');
        this.cancelDialogCancel = page.getByTestId('confirm-dialog-cancel');
        this.noSubscription = page.getByTestId('no-subscription');
        this.panel = page.getByTestId('settings-billing-panel');
        this.trial = page.getByTestId('subscription-trial');
        this.grace = page.getByTestId('subscription-grace');
        this.suspended = page.getByTestId('subscription-suspended');
        this.addPaymentMethod = page.getByTestId('add-payment-method');
    }

    /**
     * Billing is a section of the settings modal, reached by fragment. The
     * `/settings/billing` route still exists for Stripe to return to, and
     * redirects here — see the redirect test in the basic spec.
     */
    async goto() {
        await this.page.goto('/dashboard#settings/billing');
        await this.panel.waitFor();
    }

    /** `goto()` again would only move the fragment; this fetches the panel afresh. */
    async reload() {
        await this.page.reload();
        await this.panel.waitFor();
    }

    async expectNoSubscription() {
        await expect(this.noSubscription).toBeVisible();
    }

    async expectPlanName(name: string) {
        await expect(this.planName).toHaveText(name);
    }

    async openCancelDialog() {
        await this.cancelButton.click();
    }

    async expectCancelDialogVisible() {
        await expect(this.cancelDialogConfirm).toBeVisible();
        await expect(this.cancelDialogCancel).toBeVisible();
    }

    async closeCancelDialog() {
        await this.cancelDialogCancel.click();
    }
}
