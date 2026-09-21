<script setup lang="ts">
import SiteLayout from '@/layouts/SiteLayout.vue';
import ProductSection from '../components/ProductSection.vue';
import type { Product } from '../../types';

defineProps<{
    products: Product[];
    /** The plan the signed-in user is subscribed to, if any. */
    currentProductId: number | null;
}>();
</script>

<template>
    <SiteLayout title="Pricing">
        <ProductSection
            :products="products"
            :current-product-id="currentProductId"
            class="from-primary/20 bg-linear-to-b to-transparent"
        >
            <div class="mx-auto max-w-4xl text-center">
                <h2
                    class="text-foreground mt-2 text-4xl font-semibold tracking-tight sm:text-5xl"
                >
                    {{ $t('Pricing that ships with your app') }}
                </h2>
                <p class="text-foreground/70 mx-auto mt-6 max-w-2xl text-lg">
                    {{
                        $t(
                            "Every plan here runs on the Billing module against Stripe's sandbox. Try the checkout: you won't be charged.",
                        )
                    }}
                </p>
            </div>
        </ProductSection>
    </SiteLayout>
</template>
