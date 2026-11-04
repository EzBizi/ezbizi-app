<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A phone spelling stored as one Workiz person, per organization (Jose,
 * 2026-11-04: names must change without a release). Takes over the pairs that
 * were CallReconciliation::ALIASES, each on Jose's word; the ones Workiz's team
 * list now matches by itself are left out (PersonNames).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_report_name_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('phone_name', 191);
            $table->string('name', 191);
            $table->timestamps();
            $table->unique(['organization_id', 'phone_name']);
        });

        $org = DB::table('organizations')->where('slug', 'a-1-chimney')->value('id');
        if ($org === null) {
            return;
        }
        foreach ([
            'Adeline Pierre Louis-Aghaji' => 'Adeline Pierrelouis', // Jose, 2026-09-14: "YES"
            'Chevel Richards' => 'Chevel Richards Turner', // Jose, 2026-10-06: "Yes, all three"
            'Danika Kaye' => 'Danika Kaye Navarroza',
            'Jay Ticoit' => 'Dieuve Ticoit', // Jose, 2026-11-03: "yes they are the same person"
            'Rolando De Lara' => 'Rolando De Lara Jr', // Jose, 2026-11-04: "yes to all 3"
        ] as $phone => $name) {
            DB::table('call_report_name_links')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $org, 'phone_name' => $phone, 'name' => $name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('call_report_name_links');
    }
};
