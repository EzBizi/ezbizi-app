<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Task Board members and custom boards (Jose, 2026-10-13, inbox 0120).
 *
 * A custom board can sit on a company or franchise that already has its
 * automatic board, so the three per-kind unique indexes go. `auto_key`
 * (kind:id, null on custom boards) carries "one automatic board per place"
 * instead. Existing boards are main; every personal board gets its owner as
 * its owner member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_boards', function (Blueprint $table) {
            $table->string('visibility', 16)->default('main')->after('name');
            $table->string('auto_key', 64)->nullable()->unique()->after('kind');
            $table->foreignUuid('created_by_user_id')->nullable()->after('owner_user_id')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });

        DB::table('task_boards')->update([
            'auto_key' => DB::raw("CONCAT(kind, ':', COALESCE(company_id, franchise_id, owner_user_id))"),
        ]);

        Schema::table('task_boards', function (Blueprint $table) {
            $table->dropUnique(['kind', 'company_id']);
            $table->dropUnique(['kind', 'franchise_id']);
            $table->dropUnique(['kind', 'owner_user_id']);
        });

        Schema::create('task_board_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16); // owner | member | viewer
            $table->timestamps();
            $table->unique(['board_id', 'user_id']);
        });

        $now = now();
        DB::table('task_boards')->where('kind', 'user')->whereNotNull('owner_user_id')
            ->orderBy('id')
            ->chunk(500, function ($boards) use ($now) {
                DB::table('task_board_members')->insertOrIgnore($boards->map(fn ($b) => [
                    'id' => (string) Str::uuid(),
                    'organization_id' => $b->organization_id,
                    'board_id' => $b->id,
                    'user_id' => $b->owner_user_id,
                    'role' => 'owner',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_board_members');
        DB::table('task_boards')->whereNull('auto_key')->delete();
        Schema::table('task_boards', function (Blueprint $table) {
            $table->unique(['kind', 'company_id']);
            $table->unique(['kind', 'franchise_id']);
            $table->unique(['kind', 'owner_user_id']);
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropUnique(['auto_key']);
            $table->dropColumn(['visibility', 'auto_key', 'deleted_at']);
        });
    }
};
