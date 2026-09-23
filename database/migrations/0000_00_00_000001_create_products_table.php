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
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Identifiers
            $table->string('sku')->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // Set when the product came from a payment provider's catalog
            $table->string('provider')->nullable();
            $table->string('provider_product_id')->nullable();

            // What buying it gives, and what it grants: see PlanKind and Entitlements
            $table->string('kind')->default('subscription');
            $table->json('entitlements')->nullable();

            // Days of free trial this plan offers, once per customer.
            $table->unsignedSmallInteger('trial_days')->nullable();
            $table->foreignId('replaces_product_id')->nullable()->constrained('products')->restrictOnDelete();

            // Non-null only for the free plan, so the unique index allows one.
            $table->unsignedTinyInteger('free_plan')->storedAs("case when kind = 'free' then 1 end")->nullable()->unique();

            // Display & Marketing
            $table->integer('display_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_highlighted')->default(false);

            // Configuration
            $table->json('features')->nullable();
            $table->json('metadata')->nullable();

            // Status
            $table->boolean('is_active')->default(false);

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('is_active');
            $table->unique(['provider', 'provider_product_id']);
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
