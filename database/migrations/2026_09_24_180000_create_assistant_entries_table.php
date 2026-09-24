<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI Assistance Library: every answer the staff assistant gives.
 *
 * Moved out of code on 2026-09-24 so the business edits its own replies -
 * Jose: "a screen in the app where im able to change the replies from the menu
 * myself". Until today the answers were constants in WhatsAppStaffBot, and a
 * changed finance link waited on a release.
 *
 * ── Owned like everything else ───────────────────────────────────
 *
 * organization, then optionally a company, then optionally a franchise - the
 * same place columns Training's guides use. An organization-level answer is
 * everybody's; a franchise's answer is that franchise's, and is only shown to
 * somebody who works there. Who may EDIT which is the policy's job.
 *
 * ── Why the lengths are what they are ────────────────────────────
 *
 * `section` and `title` become WhatsApp list rows, which Meta caps at 24
 * characters - and it refuses the WHOLE list for one over-long row, which a tech
 * experiences as no reply at all. `description` is a row description, capped at
 * 72. `body` is a text message, capped at 4096. The columns hold the limit so a
 * value that would silently kill the menu cannot be stored in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained('franchises')->nullOnDelete();

            // The menu groups answers by section: "NFPA 211" holds six, "CSIA"
            // holds one. A section with one answer opens straight to it.
            $table->string('section', 24);
            $table->string('title', 24);
            $table->string('description', 72)->nullable();
            $table->text('body');

            // `public` or `staff`. Staff answers are never drawn for somebody
            // the assistant cannot place as staff - see AssistantBrain.
            $table->string('audience', 16);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'company_id', 'franchise_id']);
            $table->index(['organization_id', 'section', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_entries');
    }
};
