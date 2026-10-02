<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_workspaces', function (Blueprint $table) {
            // A string, not `ulid()`: PostgreSQL makes that char(26) and pads a short test
            // ID such as '5'. Real ULIDs fill it.
            $table->string('id')->primary();
            $table->string('name');
            $table->string('billing_email');
            $table->json('members');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_workspaces');
    }
};
