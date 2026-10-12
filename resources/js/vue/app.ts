import { registerGlobalComponent } from '@saucebase/vue/lib/globalComponents';
import { registerIcon } from '@saucebase/vue/lib/navigation';
import IconCreditCard from '~icons/lucide/credit-card';
import IconSparkles from '~icons/lucide/sparkles';
import PlanName from './components/PlanName.vue';

export function setup() {
    registerIcon('billing', IconCreditCard);
    registerIcon('upgrade', IconSparkles);
    registerGlobalComponent('user-subtitle', PlanName);
}
