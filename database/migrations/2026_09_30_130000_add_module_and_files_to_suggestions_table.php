<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suggestions', function (Blueprint $table) {
            // The sidebar tab the author says it is about, as its module key
            // (`jobs`, `hr`), or `settings`. Null is "something new / not sure".
            $table->string('module', 64)->nullable()->after('body');

            // [{name, mime, size, disk, path}] — screenshots, photos, .md notes.
            // A column rather than the shared `attachments` table on purpose:
            // AttachmentUploader writes an organization audit row, and this
            // module's whole point is that the organization cannot see it.
            $table->json('files')->nullable()->after('module');
        });
    }

    public function down(): void
    {
        Schema::table('suggestions', function (Blueprint $table) {
            $table->dropColumn(['module', 'files']);
        });
    }
};
