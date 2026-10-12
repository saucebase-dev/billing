import { expect, type Locator, type Page } from '@playwright/test';

/**
 * The module's own checkout page, which is only reached while the
 * `redirect_to_gateway` setting is off. With it on — the default — buyers go
 * straight to the provider and never see this.
 */
export class CheckoutPage {
    readonly page: Page;
    readonly checkoutForm: Locator;
    readonly emailInput: Locator;
    readonly couponToggle: Locator;
    readonly couponInput: Locator;
    readonly submitButton: Locator;
    readonly orderSummary: Locator;
    readonly productName: Locator;
    readonly total: Locator;

    constructor(page: Page) {
        this.page = page;
        this.checkoutForm = page.getByTestId('checkout-form');
        this.emailInput = page.getByTestId('checkout-email');
        this.couponToggle = page.getByTestId('checkout-coupon-toggle');
        this.couponInput = page.getByTestId('checkout-coupon');
        this.submitButton = page.getByTestId('checkout-submit');
        this.orderSummary = page.getByTestId('order-summary');
        this.productName = page.getByTestId('checkout-product-name');
        this.total = page.getByTestId('checkout-total');
    }

    async startFromPricing(slug: string) {
        await this.page.goto('/pricing');
        await this.page
            .locator(
                `[data-testid="product-card-${slug}"] [data-testid="get-started-button"]`,
            )
            .click();
        await this.page.waitForURL(/\/billing\/checkout\/.+/);
    }

    async expectProductName(name: string) {
        await expect(this.productName).toHaveText(name);
    }

    async expectFormVisible() {
        await expect(this.checkoutForm).toBeVisible();
        await expect(this.emailInput).toBeVisible();
        await expect(this.submitButton).toBeVisible();
    }
}
