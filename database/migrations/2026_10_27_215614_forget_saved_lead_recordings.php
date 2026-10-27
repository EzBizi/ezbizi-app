<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Jose, 2026-10-27: "I don't want you saving any call recordings." Deletes the
 * lead call recordings 7d9cfd4e8 copied to lead-recordings/ and clears the
 * column that named them. The column stays, always empty: its migration has
 * shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Storage::disk('local')->deleteDirectory('lead-recordings');
        DB::table('lead_conversations')->whereNotNull('recording_path')->update(['recording_path' => null]);
    }

    public function down(): void
    {
        // Deleted audio cannot come back.
    }
};
