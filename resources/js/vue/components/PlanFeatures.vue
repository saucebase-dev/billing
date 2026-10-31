<script setup lang="ts">
import { Check } from '@lucide/vue';

/** A plan's marketing features, as on its pricing card: the first few, then a count. */
const props = withDefaults(
    defineProps<{ features: string[]; limit?: number }>(),
    {
        limit: 3,
    },
);
</script>

<template>
    <ul v-if="features.length" class="text-muted-foreground space-y-1 text-sm">
        <li
            v-for="feature in features.slice(0, props.limit)"
            :key="feature"
            class="flex items-center gap-2"
        >
            <Check class="text-primary size-4 shrink-0" />
            {{ feature }}
        </li>
        <li v-if="features.length > props.limit" class="pl-6">
            {{
                $t('+:count more', {
                    count: String(features.length - props.limit),
                })
            }}
        </li>
    </ul>
</template>
