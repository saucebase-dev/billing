<script setup lang="ts">
import SiteLayout from '@/layouts/SiteLayout.vue';
import ProductSection from '../components/ProductSection.vue';
import type { Product } from '../../types';

defineProps<{
    products: Product[];
}>();
</script>

<template>
    <SiteLayout title="Pricing">
        <ProductSection
            :products="products"
            class="from-primary/20 bg-linear-to-b to-transparent"
        >
            <div class="mx-auto max-w-4xl text-center">
                <h2
                    class="text-foreground mt-2 text-4xl font-semibold tracking-tight sm:text-5xl"
                >
                    {{ $t('Price table demonstration') }}
                </h2>
                <p class="text-foreground/70 mx-auto mt-6 max-w-2xl text-lg">
                    {{
                        $t(
                            "This is a live example using Stripe Sandbox, so you won't be charged. Fell free to test the checkout flow",
                        )
                    }}
                </p>
            </div>
        </ProductSection>
    </SiteLayout>
</template>
