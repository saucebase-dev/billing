<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboards filter by status and date range. Its own migration, so apps that
 * installed billing before it get the indexes too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->index(['status', 'currency', 'created_at']));
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->index('created_at');
            $table->index('cancelled_at');
        });
        Schema::table('checkout_sessions', fn (Blueprint $table) => $table->index(['status', 'created_at']));
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex(['status', 'currency', 'created_at']));
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['cancelled_at']);
        });
        Schema::table('checkout_sessions', fn (Blueprint $table) => $table->dropIndex(['status', 'created_at']));
    }
};
