<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One ZIP, one area PER COMPANY, not per organization (Jose, 2026-10-28):
 * companies are separate businesses, so A-1 Chimney holding 20601 must not
 * stop A1 Garage Doors drawing over it. A released ZIP is likewise one
 * company's decision. Existing releases are copied to every company that
 * has an area, since the old row did not say whose they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['service_area_zips', 'service_area_zip_releases'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignUuid('company_id')->nullable()->after('organization_id')->constrained()->cascadeOnDelete();
                $table->index('organization_id');
            });
        }

        DB::statement('UPDATE service_area_zips z JOIN franchises f ON f.id = z.franchise_id SET z.company_id = f.company_id');

        foreach (DB::table('service_area_zip_releases')->get() as $r) {
            $companies = DB::table('service_areas')->join('franchises', 'franchises.id', '=', 'service_areas.franchise_id')
                ->where('service_areas.organization_id', $r->organization_id)->distinct()->pluck('franchises.company_id');
            foreach ($companies as $i => $company) {
                $i === 0
                    ? DB::table('service_area_zip_releases')->where('id', $r->id)->update(['company_id' => $company])
                    : DB::table('service_area_zip_releases')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $r->organization_id, 'company_id' => $company,
                        'zip' => $r->zip, 'released_by' => $r->released_by, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at]);
            }
        }
        DB::table('service_area_zip_releases')->whereNull('company_id')->delete();

        foreach (['service_area_zips', 'service_area_zip_releases'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unique(['company_id', 'zip']);
                $table->dropUnique(['organization_id', 'zip']);
            });
        }
    }

    public function down(): void
    {
        foreach (['service_area_zips', 'service_area_zip_releases'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['company_id', 'zip']);
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }
};
