<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody thinks the software should do differently.
 *
 * Addressed to EzBizi, not to the customer's business — which is why this is
 * the one tenant-owned table whose rows the organization's own administrators
 * cannot read. See the model, and the design doc's §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');

            $table->text('body');

            // 'new', 'reviewing', 'approved', 'denied'. Not an enum, matching
            // every other status column here: a migration to add a state is a
            // deploy, and a state is the kind of thing that gets added.
            $table->string('status', 20)->default('new');

            // What staff wrote back. The author reads it verbatim, so it is
            // stored as they typed it rather than composed at display time.
            $table->text('decision_note')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // The author's own list, which is the query made every time the tab
            // is opened.
            $table->index(['organization_id', 'user_id', 'created_at'], 'suggestions_mine');

            // The control-plane queue: undecided first, across every tenant.
            $table->index(['status', 'created_at'], 'suggestions_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
