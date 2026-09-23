<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Foreign keys
            $table->foreignIdFor(Customer::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Price::class)->constrained()->cascadeOnDelete();

            // Provider identifiers
            // Unknown until the hand-off, which is what picks the provider.
            $table->string('provider')->nullable();
            $table->string('provider_session_id')->nullable();
            $table->string('provider_url', 2048)->nullable();

            // URLs
            $table->string('success_url')->nullable();
            $table->string('cancel_url')->nullable();

            // Kept so a hand-off can be replayed under the same idempotency key:
            // the provider refuses a replay whose parameters have changed.
            $table->string('coupon')->nullable();

            // Status
            $table->string('status')->default('pending');

            // Configuration
            $table->json('metadata')->nullable();

            // Expiration
            $table->timestamp('expires_at')->nullable();

            // The trial this checkout was issued with, and the customer's claim
            // on it. Null means undecided; zero means decided against.
            $table->unsignedSmallInteger('trial_days')->nullable();

            // What collection this checkout was issued with, so a retry sends
            // the same request even if the setting changed meanwhile.
            $table->boolean('trial_requires_payment_method')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index(['provider', 'provider_session_id']);
            $table->index('status');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_sessions');
    }
};
