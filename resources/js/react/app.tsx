import { registerGlobalComponent } from '@saucebase/react/lib/globalComponents';
import { registerIcon } from '@saucebase/react/lib/navigation';
import IconCreditCard from '~icons/lucide/credit-card';
import IconSparkles from '~icons/lucide/sparkles';
import PlanName from './components/PlanName';

export function setup() {
    registerIcon('billing', IconCreditCard);
    registerIcon('upgrade', IconSparkles);
    registerGlobalComponent('dashboard::user-menu.subtitle', PlanName);
}
