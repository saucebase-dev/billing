import SiteLayout from '@/layouts/SiteLayout';
import { useT } from '@saucebase/react/i18n';
import type { PlanActions, Product } from '../../types';
import ProductSection from '../components/ProductSection';

export default function Plans({
    products,
    priceActions,
    productActions,
}: PlanActions & { products: Product[] }) {
    const t = useT();

    return (
        <SiteLayout title={t('Pricing')}>
            <ProductSection
                products={products}
                priceActions={priceActions}
                productActions={productActions}
                className="from-primary/20 bg-linear-to-b to-transparent"
            >
                <div className="mx-auto max-w-4xl text-center">
                    <h2 className="text-foreground mt-2 text-4xl font-semibold tracking-tight sm:text-5xl">
                        {t('Choose your plan')}
                    </h2>
                    <p className="text-foreground/70 mx-auto mt-6 max-w-2xl text-lg">
                        {t(
                            'Start free and upgrade when you need more. Cancel anytime.',
                        )}
                    </p>
                </div>
            </ProductSection>
        </SiteLayout>
    );
}
