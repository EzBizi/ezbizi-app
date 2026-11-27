<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The phone-to-Workiz name pairs move onto Settings' list store
 * (reports.staff_names): one pattern for every business list, its API and its
 * screen (desk, 2026-11-04). Copied as active rows, keyed by the phone name;
 * two phone names that differ only by case are one key there, the later kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('call_report_name_links')) {
            $rows = DB::table('call_report_name_links')->orderBy('updated_at')->get();
            $kept = [];
            foreach ($rows as $row) {
                $kept[$row->organization_id][mb_strtolower($row->phone_name)] = $row;
            }
            foreach ($kept as $org => $pairs) {
                $sort = 0;
                foreach ($pairs as $row) {
                    DB::table('business_list_items')->updateOrInsert(
                        ['organization_id' => $org, 'list_key' => 'reports.staff_names', 'item_key' => $row->phone_name],
                        ['id' => (string) Str::uuid(), 'value' => json_encode(['phone_name' => $row->phone_name, 'name' => $row->name]),
                            'sort' => $sort++, 'active' => true, 'created_at' => $row->created_at, 'updated_at' => now()],
                    );
                }
            }
        }

        Schema::dropIfExists('call_report_name_links');
    }

    public function down(): void
    {
        // The rows stay on the list store; nothing to put back.
    }
};
