<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // Whoever pays: a user, or anything else implementing BillingOwner.
            // Strings, because owners' keys differ (integer users, ULID
            // workspaces). No foreign key can span types, so `Billable` detaches
            // the customer when its owner is deleted, keeping the history.
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();

            // Provider identifiers
            $table->string('provider');
            $table->string('provider_customer_id')->nullable();

            // Billing info
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();

            // Address
            $table->json('address')->nullable();

            // Configuration
            $table->json('metadata')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index(['provider', 'provider_customer_id']);
            $table->unique(['owner_type', 'owner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
