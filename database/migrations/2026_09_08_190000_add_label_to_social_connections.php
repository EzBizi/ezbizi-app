<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A name a person chose, beside the number Google gave.
 *
 * `display_name` is Google's: `405-172-9356`. It is the right thing to keep —
 * it is what the account is called in Google Ads, and it is how somebody
 * cross-checks a row against the advertising account it came from. It is also
 * useless for telling twenty-two of them apart, which is the whole problem with
 * the Leads accounts list: nobody remembers which number is the Connecticut
 * account.
 *
 * So this is additive, not a replacement. `label` is what a person typed;
 * `display_name` stays exactly as Google reports it, and the screen shows the
 * label when there is one and the number when there is not. Null means nobody
 * has named it, which is every row the day this ships.
 *
 * A separate migration from the placement column deliberately: that one
 * (2026_09_08_170000) is already on production, and a spent file's added
 * columns silently never run on an incremental deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            // Nullable with no default: "unnamed" is the honest starting state
            // and is distinguishable from "named an empty string", which the
            // controller refuses anyway by folding blank input back to null.
            $table->string('label', 80)->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
