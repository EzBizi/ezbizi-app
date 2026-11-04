<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers that texted STOP to a company (Jose, 2026-11-03: "any text we sent
 * is legal"). One row per company and number; START deletes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_opt_outs', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('e164', 32);
            $table->string('keyword', 16);
            $table->timestamp('created_at')->nullable();
            $table->primary(['organization_id', 'e164']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_opt_outs');
    }
};
