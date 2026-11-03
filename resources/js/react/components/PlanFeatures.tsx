import { useTranslation } from '@saucebase/react/i18n';
import { Check } from 'lucide-react';
import { useState } from 'react';

/** A plan's marketing features, with an expandable preview. */
export default function PlanFeatures({
    features,
    limit = 3,
}: {
    features: string[];
    limit?: number;
}) {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);
    const visibleFeatures = expanded ? features : features.slice(0, limit);

    if (!features.length) {
        return null;
    }

    return (
        <ul className="text-muted-foreground space-y-1 text-sm">
            {visibleFeatures.map((feature) => (
                <li key={feature} className="flex items-center gap-2">
                    <Check className="text-primary size-4 shrink-0" />
                    {feature}
                </li>
            ))}
            {features.length > limit && (
                <li className="pl-6">
                    <button
                        type="button"
                        aria-expanded={expanded}
                        data-testid="plan-features-toggle"
                        className="hover:text-foreground cursor-pointer hover:underline"
                        onClick={() => setExpanded(!expanded)}
                    >
                        {expanded
                            ? t('Show less')
                            : t('+:count more', {
                                  count: String(features.length - limit),
                              })}
                    </button>
                </li>
            )}
        </ul>
    );
}
