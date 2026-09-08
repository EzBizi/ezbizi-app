<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WordPress account EzBizi acts as on a customer's site.
 *
 * ── Why the credential lives here and not in its own table ──
 *
 * There is exactly one per site, it dies with the site, and every column below
 * is meaningless without the host in the same row. A `web_service_accounts`
 * table would have been a one-to-one join that no query ever needs on its own.
 *
 * ── Why the password column is not hashed like the plugin token ──
 *
 * `token_hash` is a credential somebody presents TO us, so we only ever need to
 * recognise it and a hash is right. This is the opposite direction: we present
 * it to WordPress, so we have to be able to read it back. It is encrypted at
 * rest by the model's cast rather than hashed, which is the only option that
 * works, and it is why this column is spelled out here — a reader who sees
 * "password" next to "token_hash" should not have to guess why one is hashed
 * and the other is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->string('service_user_login', 191)->nullable()->after('plugin_version');

            // Ciphertext, not a password: Laravel's encrypted cast produces a
            // base64 envelope several times the length of what went in.
            $table->text('service_password')->nullable()->after('service_user_login');

            $table->string('service_rest_url', 255)->nullable()->after('service_password');
            $table->text('service_capabilities')->nullable()->after('service_rest_url');
            $table->timestamp('service_linked_at')->nullable()->after('service_capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropColumn([
                'service_user_login',
                'service_password',
                'service_rest_url',
                'service_capabilities',
                'service_linked_at',
            ]);
        });
    }
};
