import { useT } from '@/i18n';
import SiteLayout from '@/layouts/SiteLayout';
import type { Product } from '../../types';
import ProductSection from '../components/ProductSection';

export default function Plans({ products }: { products: Product[] }) {
    const t = useT();

    return (
        <SiteLayout title="Pricing">
            <ProductSection
                products={products}
                className="from-primary/20 bg-linear-to-b to-transparent"
            >
                <div className="mx-auto max-w-4xl text-center">
                    <h2 className="text-foreground mt-2 text-4xl font-semibold tracking-tight sm:text-5xl">
                        {t('Price table demonstration')}
                    </h2>
                    <p className="text-foreground/70 mx-auto mt-6 max-w-2xl text-lg">
                        {t(
                            "This is a live example using Stripe Sandbox, so you won't be charged. Fell free to test the checkout flow",
                        )}
                    </p>
                </div>
            </ProductSection>
        </SiteLayout>
    );
}
