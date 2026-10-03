import { registerGlobalComponent } from '@/lib/globalComponents';
import { registerIcon } from '@/lib/navigation';
import IconCreditCard from '~icons/lucide/credit-card';
import IconSparkles from '~icons/lucide/sparkles';
import PlanName from './components/PlanName.vue';

export function setup() {
    registerIcon('billing', IconCreditCard);
    registerIcon('upgrade', IconSparkles);
    registerGlobalComponent('user-subtitle', PlanName);
}

/**
 * Billing module after mount logic
 * Called after the app has been mounted
 */
export function afterMount() {

}
