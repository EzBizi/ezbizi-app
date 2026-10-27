<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales tab removed (Jose, 2026-10-27: "scratch it entirely, we never ended up
 * using it"). Production held 0 rows when this was written; the create
 * migration stays untouched because it is deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sales_deals');
    }

    public function down(): void
    {
        Schema::create('sales_deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('job_id')->unique();
            $table->string('stage', 20)->default('follow_up');
            $table->string('urgency', 20)->nullable();
            $table->date('next_follow_up')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->uuid('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }
};
