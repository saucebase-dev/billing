import type { User } from '@/types';
import { usePage } from '@inertiajs/react';

/** The plan under the user's name in the sidebar; the email when there is none. */
export default function PlanName() {
    const { props } = usePage();
    const plan = (props.billing as { plan: string | null } | undefined)?.plan;
    const email = (props.auth as { user?: User } | undefined)?.user?.email;

    return <span data-testid="user-menu-plan">{plan ?? email}</span>;
}
