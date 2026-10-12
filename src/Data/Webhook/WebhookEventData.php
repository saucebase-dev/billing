<?php

namespace Modules\Billing\Data\Webhook;

/**
 * What a provider's webhook says, in the module's words. A gateway builds one of
 * these from its own payload, so nothing past the gateway reads provider JSON.
 */
interface WebhookEventData {}
