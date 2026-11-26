import preview from '@/.storybook/preview';
import { expect } from 'storybook/test';
import ProductSection from './ProductSection';

const plan = (id: number, name: string, monthly: number, extra = {}) => ({
    id,
    name,
    slug: name.toLowerCase(),
    description: null,
    features: ['Unlimited projects', 'Priority support'],
    prices: [
        { id: id * 10, amount: monthly, currency: 'usd', interval: 'month' },
        {
            id: id * 10 + 1,
            amount: monthly * 10,
            currency: 'usd',
            interval: 'year',
        },
    ],
    ...extra,
});

const products = [
    plan(1, 'Starter', 900),
    plan(2, 'Pro', 2900, { is_highlighted: true }),
    plan(3, 'Business', 7900),
];

/**
 * The pricing page's plans: a card each, and a monthly/yearly switch when a plan is
 * priced both ways. Each card's button comes from the server, per price
 * (`priceActions`) or per plan without a price (`productActions`).
 */
const meta = preview.meta({
    title: 'Billing/ProductSection',
    component: ProductSection,
    args: {
        products,
        priceActions: {
            10: 'buy',
            11: 'buy',
            20: 'buy',
            21: 'buy',
            30: 'buy',
            31: 'buy',
        },
        productActions: {},
    },
    // The heading and the footer are the page's: a story leaves them out.
    argTypes: {
        children: { control: false },
        footer: { control: false },
    },
    parameters: { layout: 'fullscreen' },
});
export default meta;

/** Three plans, monthly. Switching to yearly shows the yearly prices. */
export const Default = meta.story({
    play: async ({ canvas, userEvent }) => {
        await expect(
            canvas.getAllByTestId('price-amount')[0],
        ).toHaveTextContent('$9');

        await userEvent.click(canvas.getByRole('button', { name: 'Yearly' }));

        await expect(
            canvas.getAllByTestId('price-amount')[0],
        ).toHaveTextContent('$90');
    },
});

/** One price per plan: no switch, every plan shows. */
export const OnePricePerPlan = meta.story({
    args: {
        products: products.map((product) => ({
            ...product,
            prices: product.prices.slice(0, 1),
        })),
    },
    play: async ({ canvas }) => {
        await expect(
            canvas.queryByRole('button', { name: 'Yearly' }),
        ).toBeNull();
        await expect(canvas.getAllByTestId('price-amount')).toHaveLength(3);
    },
});

/** No plan to show. */
export const Empty = meta.story({
    args: { products: [] },
    play: async ({ canvas }) => {
        await expect(canvas.getByTestId('pricing-empty')).toBeVisible();
    },
});
