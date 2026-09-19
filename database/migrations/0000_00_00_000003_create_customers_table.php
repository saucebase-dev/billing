<?php

use App\Models\User;
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

            // Foreign keys
            // Billing history outlives the account. Deleting a user detaches the
            // customer rather than deleting it, so the subscriptions, payments and
            // invoices hanging off it survive for reconciliation and audit.
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();

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
            $table->unique('user_id');
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
