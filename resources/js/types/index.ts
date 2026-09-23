export interface PriceMetadata {
    badge?: string;
    label?: string;
    original_price?: string;
}

export interface ProductMetadata {
    badge?: string;
    tagline?: string;
    cta_label?: string;
    cta_url?: string;
    [key: string]: any;
}

export interface Price {
    id: number;
    amount: number;
    currency: string;
    interval: string | null;
    interval_count?: number;
    provider_price_id?: string;
    is_active?: boolean;
    metadata?: PriceMetadata;
}

/**
 * What a pricing card's button does, decided on the server (`PlanActions`).
 * Mirrors the PHP `PlanAction` enum; change one, change the other. `buy`,
 * `trial`, `signup`, `change` and `contact` act; the rest are disabled labels.
 */
export type PlanAction =
    | 'buy'
    | 'signup'
    | 'change'
    | 'contact'
    | 'current'
    | 'included'
    | 'later'
    | 'unavailable'
    | 'trial';

/** Button per displayed price, and per plan shown without a price. */
export interface PlanActions {
    priceActions: Record<number, PlanAction>;
    productActions: Record<number, PlanAction>;
}

export interface Product {
    id: number;
    kind?: 'free' | 'subscription' | 'lifetime' | 'one_off';
    /** Days of free trial, offered once per customer. */
    trial_days?: number | null;
    name: string;
    slug?: string;
    description: string | null;
    features: string[];
    is_highlighted?: boolean;
    prices: Price[];
    metadata?: ProductMetadata;
}

export interface CheckoutSession {
    id: number;
    uuid: string;
    price: Price & { product: Product };
    status: string;
    expires_at: string | null;
}

export type PaymentMethodCategory = 'card' | 'bank' | 'wallet' | 'unknown';

export interface PaymentMethod {
    type: string;
    category: PaymentMethodCategory;
    details: Modules.Billing.Data.PaymentMethodDetails | null;
}

export interface Subscription {
    id: number;
    status: string;
    current_period_starts_at: string | null;
    current_period_ends_at: string | null;
    cancelled_at: string | null;
    ends_at: string | null;
    plan_name: string | null;
    interval: string | null;
    /** Ending at period end because a lifetime plan replaces it. */
    replaced_by_lifetime: boolean;
    trial_ends_at: string | null;
    on_trial: boolean;
    /** When a subscription behind on payment stops working; null when nothing is owed. */
    grace_ends_at: string | null;
    /** The grace period ran out: recoverable, but granting nothing. */
    suspended: boolean;
    /** A nudge to add a card, never a verdict on what the provider will do. */
    needs_payment_method: boolean;
}

export interface Invoice {
    id: number;
    number: string | null;
    total: number;
    currency: string | null;
    status: string;
    paid_at: string | null;
    hosted_invoice_url: string | null;
}
