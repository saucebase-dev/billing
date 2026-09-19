<script setup lang="ts">
import { Button } from '@/components/ui/button';
import InputField from '@/components/ui/input/InputField.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import type { CheckoutSession } from '@modules/billing/resources/js/types';
import { computed, ref } from 'vue';
import CheckoutLayout from '../layouts/CheckoutLayout.vue';
import { getIntervalDisplay } from '../utils/intervals';

import IconCheck from '~icons/heroicons/check';
import IconLock from '~icons/heroicons/lock-closed';

const props = defineProps<{
    session: CheckoutSession;
}>();

const page = usePage();
const user = computed(() => page.props.auth?.user);

const price = computed(() => props.session.price);
const product = computed(() => price.value.product);

const form = useForm({
    email: user.value?.email ?? '',
    coupon: '',
});

const showCoupon = ref(false);

function formatPrice(amount: number, currency?: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency?.toUpperCase() ?? 'EUR',
    }).format(amount / 100);
}

function handleCheckout() {
    form.post(route('billing.checkout.store', props.session.uuid));
}
</script>

<template>
    <CheckoutLayout :title="$t('Checkout')" :back-href="route('billing.plans')">
        <!-- Left: what is being bought -->
        <template #summary>
            <div data-testid="order-summary">
                <p class="text-foreground/70 text-sm">
                    {{ $t('Subscribe to :plan', { plan: product.name }) }}
                </p>

                <div class="mt-2 flex items-baseline gap-2">
                    <span
                        data-testid="checkout-total"
                        class="text-foreground text-4xl font-semibold tracking-tight"
                    >
                        {{ formatPrice(price.amount, price.currency) }}
                    </span>
                    <span class="text-foreground/70 text-sm">
                        {{ getIntervalDisplay(price.interval) }}
                    </span>
                </div>

                <!-- Line item -->
                <div class="border-border mt-8 border-t pt-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2
                                data-testid="checkout-product-name"
                                class="text-foreground font-medium"
                            >
                                {{ product.name }}
                            </h2>
                            <p
                                v-if="product.description"
                                class="text-foreground/70 mt-1 text-sm"
                                v-html="product.description"
                            />
                        </div>
                        <span class="text-foreground shrink-0 text-sm">
                            {{ formatPrice(price.amount, price.currency) }}
                        </span>
                    </div>

                    <ul v-if="product.features?.length" class="mt-4 space-y-2">
                        <li
                            v-for="(feature, index) in product.features"
                            :key="index"
                            class="text-foreground/70 flex items-center gap-2 text-sm"
                        >
                            <IconCheck class="text-primary size-4 shrink-0" />
                            {{ feature }}
                        </li>
                    </ul>
                </div>

                <!-- Totals -->
                <div class="border-border mt-6 space-y-2 border-t pt-6">
                    <div
                        class="text-foreground/70 flex items-center justify-between text-sm"
                    >
                        <span>{{ $t('Subtotal') }}</span>
                        <span>{{
                            formatPrice(price.amount, price.currency)
                        }}</span>
                    </div>
                    <p class="text-foreground/50 text-xs">
                        {{
                            $t(
                                'Tax will be calculated at payment if applicable',
                            )
                        }}
                    </p>

                    <!-- Hidden until asked for, so it does not send anyone off
                         hunting for a code they do not have. -->
                    <button
                        v-if="!showCoupon"
                        type="button"
                        data-testid="checkout-coupon-toggle"
                        class="bg-foreground/5 text-foreground hover:bg-foreground/10 mt-2 rounded-lg px-3 py-2 text-sm font-medium transition"
                        @click="showCoupon = true"
                    >
                        {{ $t('Add promotion code') }}
                    </button>

                    <InputField
                        v-else
                        name="coupon"
                        data-testid="checkout-coupon"
                        type="text"
                        :label="$t('Promotion code')"
                        :placeholder="$t('Enter your code')"
                        v-model="form.coupon"
                    />
                </div>

                <div
                    class="border-border mt-4 flex items-center justify-between border-t pt-4"
                >
                    <span class="text-foreground font-semibold">
                        {{ $t('Total due today') }}
                    </span>
                    <span class="text-foreground text-lg font-bold">
                        {{ formatPrice(price.amount, price.currency) }}
                    </span>
                </div>

                <p
                    v-if="!price.interval"
                    class="text-foreground/50 mt-3 text-xs"
                >
                    {{
                        $t(
                            'This is a one-time payment. You will not be charged again.',
                        )
                    }}
                </p>
            </div>
        </template>

        <!-- Right: what we need from you -->
        <h2 class="text-foreground text-lg font-semibold">
            {{ $t('Billing information') }}
        </h2>

        <form
            data-testid="checkout-form"
            @submit.prevent="handleCheckout"
            class="mt-6 space-y-4"
        >
            <InputField
                name="email"
                data-testid="checkout-email"
                type="email"
                :label="$t('Email')"
                :placeholder="$t('Enter your email')"
                autocomplete="email"
                required
                v-model="form.email"
            />

            <p class="text-foreground/50 text-xs">
                {{
                    $t(
                        'Your billing address is collected on the payment page when it is needed.',
                    )
                }}
            </p>

            <Button
                type="submit"
                data-testid="checkout-submit"
                class="mt-6 w-full text-base"
                :disabled="form.processing"
            >
                {{
                    form.processing
                        ? $t('Redirecting...')
                        : $t('Proceed to Payment')
                }}
            </Button>

            <div
                class="text-foreground/50 flex items-center justify-center gap-1.5 text-xs"
            >
                <IconLock class="size-3.5" />
                {{ $t('Payments are secure and encrypted') }}
            </div>

            <!-- Guarded: both routes belong to the host app, and a site without
                 them would otherwise take the whole checkout page down. -->
            <p
                v-if="route().has('terms') && route().has('privacy')"
                class="text-foreground/50 text-center text-xs"
            >
                {{ $t('By continuing you agree to our') }}
                <a
                    :href="route('terms')"
                    class="hover:text-foreground underline underline-offset-4"
                >
                    {{ $t('Terms of Service') }}
                </a>
                {{ $t('and') }}
                <a
                    :href="route('privacy')"
                    class="hover:text-foreground underline underline-offset-4"
                >
                    {{ $t('Privacy Policy') }}
                </a>
            </p>
        </form>
    </CheckoutLayout>
</template>
