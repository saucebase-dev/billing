<script setup lang="ts">
import type { User } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

/** The plan under the user's name in the sidebar; the email when there is none. */
const subtitle = computed(
    () =>
        (page.props.billing as { plan: string | null } | undefined)?.plan ??
        (page.props.auth as { user?: User } | undefined)?.user?.email,
);
</script>

<template>
    <span data-testid="user-menu-plan">{{ subtitle }}</span>
</template>
