<?php

use App\Models\WebSite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A second credential per site, and this one is meant to be seen.
 *
 * ── Why a site needs two ──
 *
 * `token_hash` is a SECRET. It is shown once, only its SHA-256 is stored, and
 * it authenticates a WordPress plugin running on somebody's server where no
 * visitor can read it.
 *
 * A site with no WordPress has nowhere to put a secret. A `<script>` tag on a
 * public page is not a private place, so pasting the token there would publish
 * it — and a credential that is published is not a credential, whatever the
 * column is called. The honest answer is a second key that is PUBLIC BY
 * DESIGN: read-only, values-only, safe in page source, and revocable on its own
 * without breaking the plugin.
 *
 * This is the split Stripe makes between a secret key and a publishable one,
 * for the same reason: the browser needs an identifier, not an authorisation.
 *
 * ── Stored in plaintext, deliberately ──
 *
 * The token is hashed because nobody should be able to read it back. This one
 * is printed on the customer's own web pages, so hashing it would protect
 * nothing and would stop the SEO tab showing somebody the key they are supposed
 * to paste. Unique so a lookup is an index hit and two sites can never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->string('public_key', 64)->nullable()->unique()->after('token_hash');
        });

        /*
         * Backfill every site registered before this existed, so no customer
         * has to re-register to get a key. Chunked and keyed by id rather than
         * loaded whole: the table is small today and this runs on a box where
         * that is not a promise about tomorrow.
         */
        DB::table('web_sites')->select('id')->whereNull('public_key')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('web_sites')
                        ->where('id', $row->id)
                        ->update(['public_key' => WebSite::newPublicKey()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropUnique(['public_key']);
            $table->dropColumn('public_key');
        });
    }
};
