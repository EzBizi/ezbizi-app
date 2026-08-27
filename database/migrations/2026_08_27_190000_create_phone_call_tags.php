<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a call was about, chosen by whoever answered it.
 *
 * ── Why the tag is a row and not a string on the call ────────────
 *
 * The point of tagging is counting: "how many callbacks last month", "how much
 * of Tuesday was warranty work". Free text cannot be counted — "no answer",
 * "No Answer" and "noanswer" are three answers to one question. A named row
 * means renaming a tag renames it everywhere and the history still adds up.
 *
 * ── Why it is org-wide rather than per phone account ─────────────
 *
 * A tag is a business taxonomy, not a property of a Twilio account. A group
 * that wants "warranty" counted wants it counted across every company it owns,
 * or the report cannot be added together.
 *
 * ── Why the call keeps a tag and not the other way round ─────────
 *
 * One call is about one thing. Several tags per call sounds more flexible and
 * makes every report ambiguous — a call tagged both "sale" and "complaint"
 * appears in both totals, so the totals stop summing to the number of calls.
 * If that is genuinely needed later it is a pivot table and a decision, not a
 * column that quietly grew.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_call_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);

            // Retired rather than deleted: a tag used on a thousand calls last
            // year must keep naming them, while nobody may pick it today.
            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('phone_calls', function (Blueprint $table) {
            // nullOnDelete, not cascade: deleting a tag must never delete the
            // calls it described.
            $table->foreignUuid('tag_id')->nullable()->after('user_id')
                ->constrained('phone_call_tags')->nullOnDelete();

            // Who said so and when — a tag with no author is a number nobody
            // can question later.
            $table->foreignUuid('tagged_by')->nullable()->after('tag_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('tagged_at')->nullable()->after('tagged_by');

            $table->index(['organization_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropForeign(['tag_id']);
            $table->dropForeign(['tagged_by']);
            $table->dropIndex(['organization_id', 'tag_id']);
            $table->dropColumn(['tag_id', 'tagged_by', 'tagged_at']);
        });

        Schema::dropIfExists('phone_call_tags');
    }
};
