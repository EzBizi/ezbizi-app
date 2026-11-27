<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter dual design (suggestion 01a1f9ed, 2026-11-20).
 *
 * A newsletter keeps its custom HTML and its blocks as two independent
 * designs; `active_design` ('html' or 'blocks') names the one preview, test
 * and send render. It changes only by an explicit "Use this design". Before
 * this, an empty `html_override` meant "blocks", so switching views wiped the
 * HTML (draft 01a1f9ca lost its revision-6 design that way).
 *
 * Backfill: 'html' where the row (or revision) has custom HTML, else 'blocks',
 * which is exactly what each one rendered before, so no output changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['newsletters', 'newsletter_revisions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('active_design', 8)->default('blocks')->after('html_override');
            });
            DB::table($table)->whereNotNull('html_override')->where('html_override', '!=', '')
                ->update(['active_design' => 'html']);
        }
    }

    public function down(): void
    {
        foreach (['newsletters', 'newsletter_revisions'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('active_design'));
        }
    }
};
