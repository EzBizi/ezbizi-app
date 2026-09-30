<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images a guide holds itself, rather than links to somebody else's server.
 *
 * Until now a guide screen could only carry a public http(s) link, which is
 * all authoring needed. Importing A1 Tools' training changes that: A1's
 * images live on A1's own server, which is going away, so they are COPIED
 * into EzBizi's private storage (docs/A1-TOOLS-TRAINING-MIGRATION.md). A
 * private file has no public URL for a screen to hold, so a screen now holds
 * a reference to a row here instead, and readers are handed a short-lived
 * signed link to it after their read of the guide has been authorised.
 *
 * One row per (guide, checksum). Owned by ONE guide rather than shared by
 * content across guides: deleting a guide cascades here, and a file two
 * guides shared would be deleted from under the survivor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guide_id')->constrained('training_guides')->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->char('sha256', 64);
            $table->string('mime', 64);
            $table->unsignedInteger('bytes');
            $table->timestamps();

            $table->unique(['guide_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_media');
    }
};
