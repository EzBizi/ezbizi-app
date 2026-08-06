<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real profile, and sessions somebody can recognise.
 *
 * ── Names are stored in parts, and `name` stays ──────────────────
 *
 * `users.name` is read by the audit trail, the API, every list screen and the
 * signature push. Replacing it with three columns would mean touching all of
 * them at once, so it stays as the composed display name and the parts sit
 * beside it. One is derived from the others; the app owns keeping them in step.
 *
 * ── Date of birth and gender are sensitive ───────────────────────
 *
 * §9 treats these as personal data with a legal weight the rest of the record
 * does not carry. Both are NULLABLE and neither is required anywhere — an app
 * that refuses to work until somebody states their gender has made a demand it
 * cannot justify. They exist because HR modules will need them; nothing else
 * should read them.
 *
 * ── Session context ──────────────────────────────────────────────
 *
 * A session list showing five rows that all say "web" tells nobody which one to
 * revoke, which makes "sign out everywhere else" the only usable control and
 * a targeted revoke impossible. The user agent and the address at sign-in are
 * what make a row identifiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 60)->nullable()->after('name');
            $table->string('middle_name', 60)->nullable()->after('first_name');
            $table->string('last_name', 60)->nullable()->after('middle_name');

            $table->string('phone', 40)->nullable()->after('email');

            // Free text with a length bound, not an enum. A closed list is a
            // schema change every time it is wrong for somebody, and it will be
            // wrong for somebody. The client suggests the common answers and
            // accepts anything else.
            $table->string('gender', 40)->nullable();

            $table->date('date_of_birth')->nullable();

            // A path inside the app's own storage, never a URL. A URL column
            // accepts an external address, and an avatar loaded from somewhere
            // else is a beacon that reports every viewer to whoever hosts it.
            $table->string('avatar_path', 255)->nullable();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('user_agent', 400)->nullable()->after('last_used_at');

            // 45 characters: an IPv6 address with an IPv4 tail is 45, and this
            // is exactly the sort of column that gets sized for IPv4 and starts
            // silently truncating years later.
            $table->string('ip_address', 45)->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'middle_name', 'last_name',
                'phone', 'gender', 'date_of_birth', 'avatar_path',
            ]);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['user_agent', 'ip_address']);
        });
    }
};
