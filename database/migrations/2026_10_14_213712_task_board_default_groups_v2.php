<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jose, 2026-10-14: default groups are Open Tasks, Recurring Tasks, Completed
 * Tasks, Company Files, Guidelines. Renames only groups still carrying the old
 * default names, then appends the two new groups to boards that lack them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['This week' => 'Open Tasks', 'Next week' => 'Recurring Tasks', 'Done' => 'Completed Tasks'] as $old => $new) {
            DB::table('task_groups')->where('name', $old)->update(['name' => $new, 'updated_at' => now()]);
        }

        DB::table('task_boards')->whereNull('deleted_at')->orderBy('id')->each(function ($board) {
            $position = (int) DB::table('task_groups')->where('board_id', $board->id)->max('position');
            foreach ([['Company Files', '#FDAB3D'], ['Guidelines', '#784BD1']] as [$name, $colour]) {
                if (DB::table('task_groups')->where('board_id', $board->id)->where('name', $name)->exists()) {
                    continue;
                }
                DB::table('task_groups')->insert([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $board->organization_id,
                    'board_id' => $board->id,
                    'name' => $name,
                    'colour' => $colour,
                    'position' => ++$position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void {}
};
