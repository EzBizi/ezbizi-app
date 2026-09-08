<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training's place columns move from `nullOnDelete` to `restrictOnDelete`
 * (coherence audit finding 3, 2026-09-08).
 *
 * §3: `company_id IS NULL` means the ORGANIZATION, and
 * `InventoryVisibility::constrain` tests `whereNull('company_id')` first.
 * A `nullOnDelete` company_id/franchise_id means deleting a company would
 * silently re-parent every one of its guides, tests and assignments onto
 * the organization and publish them tenant-wide the moment the delete
 * commits — a re-parent BY DELETION, which §3 says cannot happen, silently
 * or otherwise.
 *
 * `Services` (2026_08_25_160000) already chose `restrictOnDelete` for the
 * identical pair, for the identical reason (its own comment: "MySQL
 * refuses SET NULL on a generated column" is the proximate cause there,
 * but the column choice is the same one this migration makes). Training's
 * original migration (2026_08_29_110000) chose the widening option
 * instead. That file has already run in production and is never edited —
 * a spent migration's changed constraint would silently never apply — so
 * this is a NEW file, not a change to that one.
 *
 * Not reachable today: no company or franchise delete endpoint exists yet
 * for either place. Blocking the delete with a loud foreign-key violation,
 * the day one ships, is correct — it surfaces the conflict rather than
 * silently widening every row underneath.
 */
return new class extends Migration
{
    private const TABLES = ['training_guides', 'training_tests', 'training_assignments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropForeign(['franchise_id']);
            });

            Schema::table($table, function (Blueprint $table) {
                $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
                $table->foreign('franchise_id')->references('id')->on('franchises')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropForeign(['franchise_id']);
            });

            Schema::table($table, function (Blueprint $table) {
                $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
                $table->foreign('franchise_id')->references('id')->on('franchises')->nullOnDelete();
            });
        }
    }
};
