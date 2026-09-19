<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

import IconArrowLeft from '~icons/heroicons/arrow-left';

defineProps<{
    title?: string;
    backHref?: string;
}>();
</script>

<template>
    <div class="bg-background min-h-screen lg:grid lg:grid-cols-2">
        <Head :title="title" />

        <!-- What is being bought. Tinted and divided, so the eye reads the page
             as two halves: the offer, and what is being asked of you. -->
        <section
            class="bg-foreground/3 relative z-10 flex justify-center px-10 py-10 lg:justify-end lg:py-16 lg:shadow-[24px_0_60px_-24px_rgba(0,0,0,0.15)]"
        >
            <div class="w-full max-w-md">
                <div class="flex items-center gap-3">
                    <!-- The label is revealed on hover rather than always shown,
                         so the logo stays the thing you read first. -->
                    <Link
                        v-if="backHref"
                        :href="backHref"
                        data-testid="checkout-back"
                        class="group text-foreground/70 hover:text-foreground flex shrink-0 items-center gap-1 transition"
                        :aria-label="$t('Back')"
                    >
                        <span
                            class="border-border group-hover:bg-foreground/5 flex size-8 items-center justify-center rounded-full border transition"
                        >
                            <IconArrowLeft class="size-4" />
                        </span>
                        <span
                            class="max-w-0 overflow-hidden text-sm whitespace-nowrap opacity-0 transition-all duration-200 group-hover:max-w-24 group-hover:opacity-100"
                        >
                            {{ $t('Back') }}
                        </span>
                    </Link>

                    <Link :href="route('index')">
                        <AppLogo size="sm" />
                    </Link>
                </div>

                <div class="mt-10">
                    <slot name="summary" />
                </div>
            </div>
        </section>

        <!-- What we need from you. -->
        <section
            class="flex justify-center px-10 py-10 lg:justify-start lg:py-16"
        >
            <div class="w-full max-w-md">
                <slot />
            </div>
        </section>
    </div>
</template>
