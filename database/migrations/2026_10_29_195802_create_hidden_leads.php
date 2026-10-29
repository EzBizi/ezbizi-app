<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads › Manage › Hidden (Jose, 2026-10-29): private conversations on a
 * business line - Bar's WhatsApp carries financial talk - kept from everyone
 * without `leads.hidden.view`. A lead is hidden by `hidden_at`; a number is
 * hidden for good by a row here, which hides every lead from it, now and later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->index();
            $table->uuid('hidden_by_user_id')->nullable();
        });

        Schema::create('lead_hidden_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            // The last ten digits, the way leads compare phones.
            $table->char('digits', 10);
            $table->uuid('hidden_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'digits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_hidden_numbers');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['hidden_at']);
            $table->dropColumn(['hidden_at', 'hidden_by_user_id']);
        });
    }
};
