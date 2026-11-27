import preview from '@/.storybook/preview';
import { expect } from 'storybook/test';
import ProductCard from './ProductCard';

const pro = {
    id: 2,
    name: 'Pro',
    slug: 'pro',
    description: 'For teams that ship every week.',
    features: ['Unlimited projects', 'Priority support', 'Custom domains'],
    prices: [],
};

const monthly = { id: 20, amount: 2900, currency: 'usd', interval: 'month' };

/**
 * One plan on the pricing page: name, price, and a button for what the visitor may do
 * with it. The server decides the button (`action`); the card only shows it. Buying
 * starts a checkout, which a story logs in the Actions panel instead.
 */
const meta = preview.meta({
    title: 'Billing/ProductCard',
    component: ProductCard,
    args: { product: pro, price: monthly, action: 'buy' },
    decorators: [
        (Story) => (
            <div className="max-w-sm p-10">
                <Story />
            </div>
        ),
    ],
});
export default meta;

/** A plan the visitor can buy. */
export const Buy = meta.story({
    play: async ({ canvas }) => {
        await expect(canvas.getByTestId('price-amount')).toHaveTextContent(
            '$29',
        );
        await expect(
            canvas.getByRole('button', { name: 'Get started' }),
        ).toBeEnabled();
    },
});

/** The plan to pick: highlighted, with a badge. */
export const Highlighted = meta.story({
    args: { product: { ...pro, is_highlighted: true } },
    play: async ({ canvas }) => {
        await expect(canvas.getByTestId('product-badge')).toHaveTextContent(
            'Most popular',
        );
    },
});

/** On promotion: the regular price struck through, and how long the offer lasts. */
export const Promotion = meta.story({
    args: {
        price: {
            ...monthly,
            promotional_amount: 1900,
            promotion: {
                code: 'LAUNCH',
                percent_off: 35,
                amount_off: null,
                currency: null,
                duration: 'repeating',
                duration_in_months: 3,
                max_redemptions: 100,
                remaining: 12,
                expires_at: null,
            },
        },
    },
    play: async ({ canvas }) => {
        await expect(canvas.getByTestId('price-struck')).toHaveTextContent(
            '$29',
        );
        await expect(canvas.getByTestId('price-amount')).toHaveTextContent(
            '$19',
        );
        await expect(canvas.getByTestId('promotion-notes')).toHaveTextContent(
            '12 of 100 left',
        );
    },
});

/** A free trial before the first payment. */
export const Trial = meta.story({
    args: { product: { ...pro, trial_days: 14 }, action: 'trial' },
    play: async ({ canvas }) => {
        await expect(
            canvas.getByRole('button', { name: 'Start 14-day free trial' }),
        ).toBeEnabled();
    },
});

/** The plan the visitor is on: nothing to do. */
export const Current = meta.story({
    args: { action: 'current' },
    play: async ({ canvas }) => {
        await expect(
            canvas.getByRole('button', { name: 'Current plan' }),
        ).toBeDisabled();
    },
});

/** A subscriber on another plan: a link to change plans. */
export const Change = meta.story({
    args: { action: 'change' },
    play: async ({ canvas }) => {
        await expect(
            canvas.getByRole('link', { name: 'Change plan' }),
        ).toBeVisible();
    },
});

/** No price: the visitor talks to sales. */
export const Contact = meta.story({
    args: {
        product: {
            ...pro,
            name: 'Enterprise',
            slug: 'enterprise',
            metadata: { cta_url: '#', cta_label: 'Talk to sales' },
        },
        price: undefined,
        action: 'contact',
    },
    play: async ({ canvas }) => {
        await expect(canvas.getByText('Contact us')).toBeVisible();
        await expect(
            canvas.getByRole('link', { name: 'Talk to sales' }),
        ).toHaveAttribute('href', '#');
    },
});
