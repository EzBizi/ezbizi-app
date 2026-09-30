<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which EzBizi row each imported A1 Tools row became.
 *
 * Two jobs, one table. It makes `training:import-a1-tools` idempotent — a
 * second run finds every A1 id already recorded and changes nothing, and a
 * later bundle adds only what is new (the desk expects exactly that for the
 * three current staff not yet in EzBizi). And it IS the rollback manifest:
 * every id the importer created, by kind, in one place.
 *
 * A separate table rather than an `a1_id` column on four Training tables,
 * because this is a fact about one migration and the columns would outlive
 * it on every future row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_import_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);      // 'a1-tools'
            $table->string('kind', 32);        // guide, test, attempt, progress, media
            $table->string('source_id', 128);  // A1's id as text; media is <guide>:<sha256>
            $table->uuid('target_id');         // the EzBizi row it became
            $table->timestamps();

            // Named: the default is 67 characters and MySQL stops at 64 — the
            // same limit this module's first migration hit.
            $table->unique(['organization_id', 'source', 'kind', 'source_id'], 'training_import_keys_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_import_keys');
    }
};
