import { test as setup } from '@saucebase/laravel-playwright';

setup('seed billing subscriber fixtures', async ({ laravel }) => {
    await laravel.callFunction(
        String.raw`Modules\Billing\Tests\Support\BillingTestHelper::createSubscriberFixtures`,
    );
    await laravel.callFunction(
        String.raw`Modules\Billing\Tests\Support\BillingTestHelper::createPricingFixtures`,
    );
    await laravel.callFunction(
        String.raw`Modules\Billing\Tests\Support\BillingTestHelper::createLifecycleFixtures`,
    );
});
