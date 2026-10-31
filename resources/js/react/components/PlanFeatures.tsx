import { useTranslation } from '@saucebase/react/i18n';
import { Check } from 'lucide-react';

/** A plan's marketing features, as on its pricing card: the first few, then a count. */
export default function PlanFeatures({
    features,
    limit = 3,
}: {
    features: string[];
    limit?: number;
}) {
    const { t } = useTranslation();

    if (!features.length) {
        return null;
    }

    return (
        <ul className="text-muted-foreground space-y-1 text-sm">
            {features.slice(0, limit).map((feature) => (
                <li key={feature} className="flex items-center gap-2">
                    <Check className="text-primary size-4 shrink-0" />
                    {feature}
                </li>
            ))}
            {features.length > limit && (
                <li className="pl-6">
                    {t('+:count more', {
                        count: String(features.length - limit),
                    })}
                </li>
            )}
        </ul>
    );
}
