import type { ModulePlaywrightOptions } from '@saucebase/core/modules';

export const playwright = {
    setups: [
        {
            name: 'billing.setup',
            testMatch: /billing\.setup\.ts/,
            dependencies: ['database.setup'],
        },
    ],
} satisfies ModulePlaywrightOptions;
