import preview from '@/.storybook/preview';
import { expect } from 'storybook/test';
import CheckoutLayout from './CheckoutLayout.vue';

/**
 * The checkout page's frame: what is being bought on the left (`summary`), what the
 * buyer fills in on the right (the default slot), and a way back.
 */
const meta = preview.meta({
    title: 'Billing/CheckoutLayout',
    component: CheckoutLayout,
    args: { title: 'Checkout', backHref: '/pricing' },
    // Slots the story fills itself: their controls would do nothing.
    argTypes: {
        summary: { control: false },
        default: { control: false },
    },
    parameters: { layout: 'fullscreen' },
    render: (args) => ({
        components: { CheckoutLayout },
        setup: () => ({ args }),
        template: `
            <CheckoutLayout v-bind="args">
                <template #summary>
                    <p class="text-muted-foreground text-sm">Subscribe to Pro</p>
                    <p class="text-4xl font-semibold">$29 <span class="text-muted-foreground text-base font-normal">/month</span></p>
                </template>
                <div class="bg-muted text-muted-foreground flex min-h-64 items-center justify-center rounded-lg border-2 border-dashed text-sm">Payment details</div>
            </CheckoutLayout>`,
    }),
});
export default meta;

/** With a way back to the pricing page. */
export const Default = meta.story({
    play: async ({ canvas }) => {
        await expect(canvas.getByTestId('checkout-back')).toBeVisible();
        await expect(canvas.getByText('Payment details')).toBeVisible();
    },
});

/** Without a page to go back to. */
export const NoBack = meta.story({
    args: { backHref: undefined },
    play: async ({ canvas }) => {
        await expect(canvas.queryByTestId('checkout-back')).toBeNull();
    },
});
