<?php

namespace Modules\Billing\Data;

class CatalogSyncReport
{
    public int $created = 0;

    public int $updated = 0;

    /** Archived at the provider and unknown here, so never imported. */
    public int $skipped = 0;

    /**
     * Provider price IDs that exist locally but not at the provider.
     *
     * @var list<string>
     */
    public array $localOnly = [];
}
