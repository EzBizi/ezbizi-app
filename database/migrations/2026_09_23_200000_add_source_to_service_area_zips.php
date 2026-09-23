<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Where a zip mapping came from, so a rebuild cannot eat a typed one.
 *
 * The zip map is about to stop being hand-entered: `scheduling:build-zip-map`
 * derives it from Workiz job history, the way A1 Tools does, and a derived map
 * is rebuilt every time the history moves. Without this column a rebuild has
 * only two options and both are wrong — wipe the table and lose whatever
 * somebody typed, or insert only and leave a zip pointing at the area it used
 * to belong to.
 *
 * NULL means a person put it there. A1's equivalent is `source = 'workiz_jobs'`
 * on derived rows, and its rebuild deletes exactly those and leaves the rest;
 * this keeps the same split with the same word, because the two importers will
 * be read side by side for as long as both systems run.
 *
 * Existing rows stay NULL. There are ten of them in production and every one
 * was typed by a person, so "nobody derived this" is accurate rather than a
 * default standing in for unknown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_area_zips', function (Blueprint $table) {
            $table->string('source', 40)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('service_area_zips', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
