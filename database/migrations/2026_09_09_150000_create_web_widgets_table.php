<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a site's chat bubble and contact form look and what they ask.
 *
 * ── Why this is not `web_values` ──
 *
 * A value is one string somebody publishes: a phone number, a licence number.
 * A widget is a shape — a list of fields, each with a label, a type and whether
 * it is required, plus the colours and the copy around them. Squeezing that
 * into key/value rows would mean inventing keys like `form.field.3.label` and
 * then writing the code that puts them back together, which is a JSON document
 * with extra steps and no schema.
 *
 * ── Why one row per site rather than one per widget ──
 *
 * The two widgets share a look. A site that sets its accent colour means it for
 * both, and a customer who had to set it twice would eventually set it twice
 * differently. `config` holds `chat` and `form` beneath one row.
 *
 * ── Why there is no default row ──
 *
 * A site with no row is not a broken site: it renders the defaults in
 * App\Support\Web\WidgetDefaults. Writing a row for every site at registration
 * would freeze today's defaults into every site ever made, so improving a
 * default would reach nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('organization_id', 36);

            // One row per site, so the unique key is the site itself.
            $table->char('site_id', 36)->unique();

            $table->json('config');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('web_sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_widgets');
    }
};
