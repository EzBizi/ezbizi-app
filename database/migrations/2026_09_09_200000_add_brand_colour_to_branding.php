<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's own colour, beside the customer's own logo.
 *
 * ── Why this exists at all ──
 *
 * The chat bubble and contact form EzBizi publishes render on the CUSTOMER's
 * website, as the customer's tool. Until now their only possible default was a
 * colour EzBizi chose, which makes every customer's contact form look like an
 * EzBizi advert until somebody notices — and the person who would notice is the
 * one least likely to open the settings screen. Jose's decision, relayed
 * through the Marketing session on 2026-09-08: default to the customer's brand.
 * That required a customer brand to exist, and there was none anywhere; the
 * absence was checked case-insensitively, for both spellings of colour, after
 * two sessions were caught by false absences the same evening.
 *
 * ── Why here rather than a table of its own ──
 *
 * `logo_path` already sits on `organizations` and `companies`, and a company
 * with no logo falls back to the organization's AT RENDER TIME — inherited,
 * never copied, so changing the organization's changes every company that never
 * set one. A brand colour is the same fact about the same subject with the same
 * inheritance, so it belongs in the same two rows, resolved the same way.
 *
 * ── Why char(7) and nullable ──
 *
 * `#rrggbb`, the one form the widget config already accepts and sanitises. NULL
 * means "we do not know this customer's colour", which is different from a
 * colour they chose, and is what makes the neutral fallback honest rather than
 * a value pretending to be theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['organizations', 'companies'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->char('brand_colour', 7)->nullable()->after('logo_aspect');
            });
        }
    }

    public function down(): void
    {
        foreach (['organizations', 'companies'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('brand_colour'));
        }
    }
};
