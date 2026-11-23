<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\Promotion;

/**
 * A promotion is a provider's promotion code the pricing page shows. The
 * provider enforces it; these columns are what the page reads, so rendering
 * never calls the provider. Its own migration, so existing installs get it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();

            $table->string('provider');
            // Null until pushed: a promotion drafted here (the demo's), like a price.
            $table->string('provider_promotion_code_id')->nullable();
            $table->string('code');

            // One of the two, as the provider's coupon has it.
            $table->decimal('percent_off', 5, 2)->nullable();
            $table->unsignedBigInteger('amount_off')->nullable();
            $table->string('currency', 3)->nullable();

            // once, repeating (for duration_in_months) or forever.
            $table->string('duration')->default('once');
            $table->unsignedInteger('duration_in_months')->nullable();

            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['provider', 'provider_promotion_code_id']);
        });

        // One promotion per price, by construction.
        Schema::table('prices', function (Blueprint $table) {
            $table->foreignIdFor(Promotion::class)->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->index('promotion_id');
        });

        // What the buyer saved, and with which code: kept on the payment, so it
        // outlives the promotion.
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('discount_amount')->default(0)->after('amount_refunded');
            $table->string('promotion_code')->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['discount_amount', 'promotion_code']));
        Schema::table('prices', fn (Blueprint $table) => $table->dropConstrainedForeignIdFor(Promotion::class));
        Schema::dropIfExists('promotions');
    }
};
