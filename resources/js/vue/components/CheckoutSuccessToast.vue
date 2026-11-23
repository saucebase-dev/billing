<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onMounted } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Congratulate whoever just came back from the payment page, on whichever section the
 * merchant chose (`after_checkout_section`), so it is not the billing panel's.
 *
 * The parameter is dropped from the URL straight away, so a refresh or a later
 * visit does not celebrate the same purchase again.
 */
onMounted(() => {
    const url = new URL(window.location.href);

    if (url.searchParams.get('checkout') !== 'success') {
        return;
    }

    toast.success(trans('You are all set!'), {
        description: trans('Your payment went through. Welcome aboard.'),
    });

    // Through the router, not history.replaceState: Inertia keeps its own copy of
    // the URL and re-renders from it, so switching panel would toast again.
    url.searchParams.delete('checkout');
    url.searchParams.delete('settings');
    router.replace({
        url: url.toString(),
        preserveState: true,
        preserveScroll: true,
    });
});
</script>

<template><slot /></template>
