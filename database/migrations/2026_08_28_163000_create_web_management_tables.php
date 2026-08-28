<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web Management — the sites a company owns, and the business facts EzBizi
 * hands their WordPress plugin.
 *
 * A SUBDOMAIN IS ITS OWN SITE. `nj.a-1chimney.com` and `a-1chimney.com` are two
 * rows with two tokens and two sets of values, because the whole point is that
 * the New Jersey site answers a different phone number. `host` is stored
 * normalized — lowercase, no scheme, no port, no trailing dot — and is unique
 * per organization; the plugin identifies itself by TOKEN, and the host it
 * reports is checked against this column rather than trusted.
 *
 * Company-scoped, not franchise-scoped. The brand owns the domain; which
 * franchise answers the phone is a value on the site, not a second owner.
 *
 * ── Why `path` defaults to '' rather than being nullable ──
 *
 * A value is either the site's answer or one page's override, and the override
 * has to be unique per page. MySQL unique indexes treat NULL as distinct, so a
 * nullable `path` would happily store the same site-wide phone number twice
 * (the same trap the inventory migration works around with catalogue_key).
 * Empty string is the site-wide row, and it is a real value that a unique index
 * can hold to.
 *
 * `token_hash` never stores the token. It is shown once at creation and once at
 * rotation, and after that neither we nor support can read it back — a token is
 * a password for a machine, and the plugin is the only thing that needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            $table->string('host', 191);
            $table->string('label', 191)->nullable();

            $table->char('token_hash', 64)->unique();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('plugin_version', 32)->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'host']);
            $table->index(['organization_id', 'company_id']);
        });

        Schema::create('web_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained('web_sites')->cascadeOnDelete();

            $table->string('path', 191)->default('');
            $table->string('key', 64);
            $table->text('value')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'path', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_values');
        Schema::dropIfExists('web_sites');
    }
};
