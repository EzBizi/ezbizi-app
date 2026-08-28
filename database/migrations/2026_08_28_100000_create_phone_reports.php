<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved question about the calls (Phone > Reports).
 *
 * ── Why the filters are a column and not columns ─────────────────
 *
 * A report is "these calls, grouped this way, over this period". The filters
 * are a set that will grow — line, person, tag, direction today, and whatever
 * the next question needs. A column each means a migration every time somebody
 * wants to ask a slightly different question, and a wide table of nulls.
 *
 * The shape is validated where it is read, not by the database, which is the
 * same bargain `phone_flows.nodes` and `phone_hours_schedules.weeks` already
 * make.
 *
 * ── Why the period is stored relative ────────────────────────────
 *
 * "Last 30 days", not two dates. A saved report is a question somebody wants
 * answered repeatedly; pinning it to August means opening it in October shows
 * August, which is never what was meant. An explicit range is still allowed for
 * the one-off case and is stored as `custom` with the dates alongside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            // last_7 | last_30 | last_90 | this_month | last_month | custom
            $table->string('period', 24)->default('last_30');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            // day | hour | tag | user | line | direction
            $table->string('group_by', 16)->default('day');

            /** @var array{number_ids?: list<string>, user_ids?: list<string>, tag_ids?: list<string>, direction?: string} */
            $table->json('filters')->nullable();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_reports');
    }
};
