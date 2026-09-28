<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What EzBizi last learned about each connected Google Ads account.
 *
 * One row per connection, overwritten by each check, never a history. It holds
 * the Local Services certification verdict (licence, insurance, background
 * check - an account that lapses stops serving ads with no warning), and is the
 * subject of the Google Ads module's automation triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads_account_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('connection_id')->constrained('social_connections')->cascadeOnDelete();
            $table->json('certification_statuses')->nullable();
            $table->json('certification_documents')->nullable();
            $table->json('certification_problems')->nullable();
            $table->unsignedSmallInteger('certification_problem_count')->default(0);
            $table->timestamp('certification_soonest_expiry')->nullable();
            $table->timestamp('certification_checked_at')->nullable();
            $table->string('certification_failed', 300)->nullable();
            $table->timestamps();

            $table->unique('connection_id', 'ads_account_state_connection_unique');
            $table->index('organization_id', 'ads_account_state_org_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads_account_states');
    }
};
