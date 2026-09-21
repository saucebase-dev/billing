import { useT } from '@/i18n';
import SiteLayout from '@/layouts/SiteLayout';
import type { Product } from '../../types';
import ProductSection from '../components/ProductSection';

export default function Plans({
    products,
    currentProductId,
}: {
    products: Product[];
    /** The plan the signed-in user is subscribed to, if any. */
    currentProductId: number | null;
}) {
    const t = useT();

    return (
        <SiteLayout title="Pricing">
            <ProductSection
                products={products}
                currentProductId={currentProductId}
                className="from-primary/20 bg-linear-to-b to-transparent"
            >
                <div className="mx-auto max-w-4xl text-center">
                    <h2 className="text-foreground mt-2 text-4xl font-semibold tracking-tight sm:text-5xl">
                        {t('Pricing that ships with your app')}
                    </h2>
                    <p className="text-foreground/70 mx-auto mt-6 max-w-2xl text-lg">
                        {t(
                            "Every plan here runs on the Billing module against Stripe's sandbox. Try the checkout: you won't be charged.",
                        )}
                    </p>
                </div>
            </ProductSection>
        </SiteLayout>
    );
}
