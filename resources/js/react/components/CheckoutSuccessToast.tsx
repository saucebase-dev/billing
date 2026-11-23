import { router } from '@inertiajs/react';
import { useT } from '@saucebase/react/i18n';
import { useEffect } from 'react';
import { toast } from 'sonner';

/**
 * Congratulate whoever just came back from the payment page, on whichever section the
 * merchant chose (`after_checkout_section`), so it is not the billing panel's.
 *
 * The parameter is dropped from the URL straight away, so a refresh or a later
 * visit does not celebrate the same purchase again.
 */
export default function CheckoutSuccessToast() {
    const t = useT();

    useEffect(() => {
        const url = new URL(window.location.href);

        if (url.searchParams.get('checkout') !== 'success') {
            return;
        }

        toast.success(t('You are all set!'), {
            description: t('Your payment went through. Welcome aboard.'),
        });

        // Through the router, not history.replaceState: Inertia keeps its own
        // copy of the URL and re-renders from it, so switching panel would
        // toast again.
        url.searchParams.delete('checkout');
        url.searchParams.delete('settings');
        router.replace({
            url: url.toString(),
            preserveState: true,
            preserveScroll: true,
        });
    }, [t]);

    return null;
}
