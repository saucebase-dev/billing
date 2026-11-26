import preview from '@/.storybook/preview';
import { expect } from 'storybook/test';
import PlanFeatures from './PlanFeatures.vue';

const features = [
    'Unlimited projects',
    'Priority support',
    'Custom domains',
    'Audit log',
    'Single sign-on',
];

/**
 * A plan's features on the billing settings page. Past `limit`, the rest fold behind a
 * "+N more" button.
 */
const meta = preview.meta({
    title: 'Billing/PlanFeatures',
    component: PlanFeatures,
    args: { features, limit: 3 },
});
export default meta;

/** More than the limit: the rest are folded. Opening them shows all. */
export const Folded = meta.story({
    play: async ({ canvas, userEvent }) => {
        await expect(canvas.queryByText('Audit log')).toBeNull();

        await userEvent.click(canvas.getByRole('button', { name: '+2 more' }));

        await expect(canvas.getByText('Audit log')).toBeVisible();
        await expect(
            canvas.getByRole('button', { name: 'Show less' }),
        ).toHaveAttribute('aria-expanded', 'true');
    },
});

/** Within the limit: every feature, no button. */
export const Short = meta.story({
    args: { features: features.slice(0, 2) },
    play: async ({ canvas }) => {
        await expect(canvas.getAllByRole('listitem')).toHaveLength(2);
        await expect(canvas.queryByRole('button')).toBeNull();
    },
});

/** No features: nothing renders. */
export const Empty = meta.story({
    args: { features: [] },
    play: async ({ canvas }) => {
        await expect(canvas.queryByRole('list')).toBeNull();
    },
});
