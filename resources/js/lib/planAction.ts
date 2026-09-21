import type { Price, Product } from '../types';

/** What the visitor already has, as sent by the pricing page. */
export interface PlanAccess {
    productId: number | null;
    /**
     * `free`: signed in with nothing bought, and `productId` is the free plan.
     * `null`: a guest.
     */
    kind: 'subscription' | 'lifetime' | 'free' | null;
}

/**
 * What a plan card's button does:
 * - `buy` starts a checkout
 * - `change` opens the provider's plan picker
 * - `contact` follows the plan's own link
 * - `current`, `included` and `unavailable` are disabled
 */
export type PlanAction =
    'buy' | 'change' | 'contact' | 'current' | 'included' | 'unavailable';

/**
 * One plan per customer. A subscriber changes plan at the provider rather than
 * buying a second one, and lifetime access covers every other plan. Checkout
 * enforces the same rules on the server; this only decides what to offer.
 */
export function planAction(
    product: Product,
    price: Price | undefined,
    access: PlanAccess,
): PlanAction {
    if (access.productId === product.id) return 'current';
    if (product.metadata?.cta_url) return 'contact';
    if (!price) return 'unavailable';

    const recurring = price.interval !== null;

    if (access.kind === 'lifetime') return 'included';
    if (access.kind === 'subscription' && recurring) return 'change';

    // A free plan never reaches the provider; a paid one it does not know yet
    // would be refused at checkout.
    if (price.amount > 0 && !price.provider_price_id) return 'unavailable';

    return 'buy';
}
