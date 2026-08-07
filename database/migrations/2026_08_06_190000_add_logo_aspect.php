<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which shape a logo is (plan §12).
 *
 * ── Two shapes, fixed sizes, nothing else ────────────────────────
 *
 * A brand mark is either roughly square — an icon, a monogram, a badge — or a
 * horizontal lockup with the name set beside it. Those are different objects
 * and they cannot substitute for each other: a wide lockup crushed into a 30px
 * square is unreadable, and a square mark stretched across an invoice header
 * looks like a mistake.
 *
 * So the column records which one was uploaded, and every surface lays it out
 * accordingly. Without it the client would be guessing from the pixel ratio of
 * an image it has not finished downloading.
 *
 * The exact sizes live in ONE place — Branding::SHAPES — because the cropper
 * produces them and the validator checks them, and two lists of numbers that
 * must agree eventually will not.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['organizations', 'companies'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                // Nullable: null means no logo at all. A logo without a shape
                // is not a state that can exist — both are written together.
                $t->string('logo_aspect', 8)->nullable()->after('logo_path');
            });
        }
    }

    public function down(): void
    {
        foreach (['organizations', 'companies'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('logo_aspect'));
        }
    }
};
