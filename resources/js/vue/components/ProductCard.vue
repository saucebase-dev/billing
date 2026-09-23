<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import type {
    PlanAction,
    Price,
    Product,
} from '@modules/billing/resources/js/types';
import { getIntervalDisplay } from '../../lib/intervals';

const props = defineProps<{
    product: Product;
    price?: Price;
    action: PlanAction;
}>();

const ctaClass = computed(() =>
    props.product.metadata?.badge || props.product.is_highlighted
        ? 'bg-primary hover:bg-primary/90 focus-visible:outline-primary text-white'
        : 'text-foreground ring-border hover:bg-foreground/10 ring-1 ring-inset',
);

const startsCheckout = computed(
    () => props.action === 'buy' || props.action === 'trial',
);

const enabled = computed(
    () => startsCheckout.value || props.action === 'signup',
);

function handleGetStarted() {
    if (props.action === 'signup') {
        router.visit(route('register'));
        return;
    }

    if (startsCheckout.value && props.price) {
        router.post(route('billing.checkout.create'), {
            price_id: props.price.id,
        });
    }
}

function formatPrice(amount: number | string, currency?: string): string {
    const cents = typeof amount === 'string' ? parseFloat(amount) : amount;
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency?.toUpperCase() ?? 'EUR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(cents / 100);
}

const priceKey = computed(() => props.price?.amount);
const isAnimating = ref(false);

watch(priceKey, () => {
    isAnimating.value = true;
    setTimeout(() => {
        isAnimating.value = false;
    }, 150);
});
</script>

<template>
    <div
        :data-testid="`product-card-${product.slug}`"
        class="relative flex h-full flex-col rounded-3xl p-8 shadow-lg"
        :class="
            product.metadata?.badge || product.is_highlighted
                ? 'ring-primary bg-card/70 shadow-lg ring-3 lg:scale-[1.05]'
                : 'bg-card/70 ring-border ring-1'
        "
    >
        <span
            v-if="product.metadata?.badge || product.is_highlighted"
            data-testid="product-badge"
            class="bg-primary absolute left-1/2 -translate-x-1/2 -translate-y-2/1 rounded-md px-3 py-1 text-xs font-semibold text-white shadow-2xl"
        >
            {{ product.metadata?.badge || $t('Most popular') }}
        </span>

        <!-- Plan Name with Badge -->
        <div class="flex items-center justify-between gap-x-4">
            <h3
                class="text-2xl font-semibold"
                :class="
                    product.metadata?.badge || product.is_highlighted
                        ? 'text-secondary dark:text-secondary-light'
                        : 'text-foreground'
                "
            >
                {{ product.name }}
            </h3>
        </div>

        <!-- Description -->
        <p
            v-if="product.description"
            class="text-foreground/70 mt-2 text-sm"
            v-html="product.description"
        ></p>

        <!-- Price -->
        <div class="mt-2">
            <!-- Original price + discount badge -->
            <div
                v-if="price?.metadata?.original_price || price?.metadata?.badge"
                class="mb-1 flex h-8 items-center gap-2"
            >
                <span
                    v-if="price?.metadata?.original_price"
                    class="text-foreground/50 text-2xl line-through"
                >
                    {{
                        formatPrice(
                            price.metadata.original_price,
                            price.currency,
                        )
                    }}
                </span>
                <span
                    v-if="price?.metadata?.badge"
                    class="text-sm font-medium text-green-600 dark:text-green-400"
                >
                    {{ price.metadata.badge }}
                </span>
            </div>
            <div v-else class="h-9" />
            <!-- Current price -->
            <div
                class="flex items-baseline gap-x-1 transition-transform duration-150"
                :class="{ 'scale-105': isAnimating }"
            >
                <template v-if="price">
                    <span
                        class="text-foreground text-5xl font-semibold tracking-tight"
                    >
                        {{ formatPrice(price.amount, price.currency) }}
                    </span>
                    <span class="text-foreground/70 text-base">
                        {{ getIntervalDisplay(price.interval) }}
                    </span>
                </template>
                <span v-else class="text-foreground text-2xl font-semibold">
                    {{ $t('Contact us') }}
                </span>
            </div>
        </div>

        <!-- Tagline from metadata -->
        <p
            v-if="product.metadata?.tagline"
            class="text-foreground/70 mt-2 text-sm italic"
        >
            {{ product.metadata.tagline }}
        </p>

        <!-- CTA: plain anchors for links that leave the app, which an Inertia
             visit would follow over XHR and fail on the provider's CORS. -->
        <a
            v-if="action === 'contact'"
            :href="product.metadata?.cta_url"
            class="mt-8 block w-full cursor-pointer rounded-xl px-4 py-3 text-center font-semibold shadow-2xl transition-all duration-200 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2"
            :class="ctaClass"
        >
            {{ product.metadata?.cta_label || $t('Get started') }}
        </a>
        <a
            v-else-if="action === 'change'"
            data-testid="change-plan-button"
            :href="route('billing.plan.change')"
            class="mt-8 block w-full cursor-pointer rounded-xl px-4 py-3 text-center font-semibold shadow-lg transition-all duration-200 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2"
            :class="ctaClass"
        >
            {{ $t('Change plan') }}
        </a>
        <button
            v-else
            data-testid="get-started-button"
            :data-action="action"
            class="mt-8 w-full cursor-pointer rounded-xl px-4 py-3 font-semibold shadow-lg transition-all duration-200 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
            :class="ctaClass"
            :disabled="!enabled"
            @click="handleGetStarted"
        >
            <template v-if="action === 'current'">{{
                $t('Current plan')
            }}</template>
            <template v-else-if="action === 'included'">{{
                $t('Included in your plan')
            }}</template>
            <template v-else-if="action === 'later'">{{
                $t('Available when your current plan ends')
            }}</template>
            <template v-else-if="action === 'unavailable'">{{
                $t('Not available')
            }}</template>
            <template v-else-if="action === 'trial'">{{
                $t('Start :days-day free trial', {
                    days: String(product.trial_days ?? 0),
                })
            }}</template>
            <template v-else>{{
                product.metadata?.cta_label || $t('Get started')
            }}</template>
        </button>

        <!-- After CTA text from metadata -->
        <div
            class="text-foreground/70 mt-2 text-center text-sm"
            v-if="product.metadata?.after_cta"
        >
            {{ $t(product.metadata.after_cta) }}
        </div>

        <!-- Features -->
        <ul
            v-if="product.features?.length"
            class="text-foreground/70 mt-6 flex-1 space-y-1 text-sm"
        >
            <li
                v-for="(feature, index) in product.features"
                :key="index"
                class="flex items-start gap-3"
            >
                <svg
                    class="text-primary mt-0.5 h-5 w-5 shrink-0"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                >
                    <path
                        fill-rule="evenodd"
                        d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                        clip-rule="evenodd"
                    />
                </svg>
                <span>{{ feature }}</span>
            </li>
        </ul>
    </div>
</template>
