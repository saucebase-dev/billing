<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed, ref } from 'vue';

/** A plan's marketing features, with an expandable preview. */
const props = withDefaults(
    defineProps<{ features: string[]; limit?: number }>(),
    {
        limit: 3,
    },
);
const expanded = ref(false);
const visibleFeatures = computed(() =>
    expanded.value ? props.features : props.features.slice(0, props.limit),
);
</script>

<template>
    <ul v-if="features.length" class="text-muted-foreground space-y-1 text-sm">
        <li
            v-for="feature in visibleFeatures"
            :key="feature"
            class="flex items-center gap-2"
        >
            <Check class="text-primary size-4 shrink-0" />
            {{ feature }}
        </li>
        <li v-if="features.length > props.limit" class="pl-6">
            <button
                type="button"
                :aria-expanded="expanded"
                data-testid="plan-features-toggle"
                class="hover:text-foreground cursor-pointer hover:underline"
                @click="expanded = !expanded"
            >
                {{
                    expanded
                        ? $t('Show less')
                        : $t('+:count more', {
                              count: String(features.length - props.limit),
                          })
                }}
            </button>
        </li>
    </ul>
</template>
