<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Which of an organization's Airtable tables feed which part of the weekly and
 * monthly report, for which company, and under which field names (Jose,
 * 2026-10-16: "it all needs to be stablished as a public product would"). Each
 * tenant links its own bases (Integrations) and maps them here (Settings).
 *
 * A-1 Chimney's existing mapping, the tables the A1 Leads reports read, is
 * written for the organization that has those bases connected; it is that
 * tenant's data, edited like any other's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('base_id', 32);
            $table->string('table_id', 32);
            // What the table is (ReportSources::ROLES).
            $table->string('role', 32);
            // The company code it belongs to; null for one shared by all (HQ, or filtered below).
            $table->string('company', 16)->nullable();
            // A shared table's rows for this company: where filter_field equals filter_value.
            $table->string('filter_field')->nullable();
            $table->string('filter_value')->nullable();
            // logical field => this table's field name, over ReportSources::FIELDS.
            $table->json('fields')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'role']);
        });

        // Which company code each branch reports as (the P&L names its tables by it).
        Schema::create('report_companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->timestamps();
        });

        $this->seedA1();
    }

    public function down(): void
    {
        Schema::dropIfExists('report_companies');
        Schema::dropIfExists('report_sources');
    }

    /** A-1 Chimney's mapping, written only where its P&L base is connected. */
    private function seedA1(): void
    {
        $orgs = DB::table('social_connections')->where('provider', 'airtable')->where('remote_id', 'appWhbwht0xgB5Yut')->pluck('organization_id')->unique();

        $perCompany = [
            ['appadTMQltMGmGrse', 'deposits', ['tbljbx2p8nsQ7adRL' => null, 'tbl81ZTowT57Ex9UD' => null, 'tblGzCgHbjzrH3gfO' => null, 'tblMvDkzefX4qqq5W' => null,
                'tblxLlYqi1uMsjEUc' => null, 'tblxmCTEwSXPahQyd' => null, 'tblhykuZPesNQgy1i' => null, 'tblz2mqN2mssahf7j' => null, 'tblBi6eDJL6MDVRSK' => null]],
            ['appWhbwht0xgB5Yut', 'pnl', ['tblDUxGZlTStCb2UH' => 'NJ', 'tblhqHFPpYAiW8WPw' => 'CT', 'tbltOW4Ye5LqwKaLa' => 'TX', 'tblpKJk2XEMlkX4lG' => 'OH',
                'tblMZBfVXxXmNzNTd' => 'IL', 'tbl1YNRbZeLgaJChN' => 'GA', 'tblENNwJUcW7ySb8P' => 'DMV', 'tblifiOssYgLOaz3X' => 'WA', 'tbl06qeoIY5E7PfxH' => 'NW',
                'tblunO193lu8YMcIq' => 'NY', 'tblhIqc5V5yH6m0H0' => 'MA']],
            ['appzD9I7nNkALmw4l', 'repair', ['tbliTYwmQl2KXzoxh' => 'NJ', 'tblLaCAXBIFj2H5hu' => 'CT', 'tblDUxGZlTStCb2UH' => 'OH', 'tblhW7kHAtYzleqde' => 'TX',
                'tblM8GHLc5ssy12nj' => 'IL', 'tblhc4CJLOkMCNopv' => 'GA', 'tbl7GHGQpvlyWg4dG' => 'DMV', 'tblo2IX58b5GWiU7E' => 'NY', 'tbl9IYzaoNxKAZJNx' => 'MA']],
            ['app6ZZgY9nHX8mMih', 'marketing', ['tblqhXDymSAR6S3G6' => null, 'tblXCVvrqnN304ERY' => null, 'tblxgh8Ow0XpuKg5B' => null]],
            ['app6ZZgY9nHX8mMih', 'hq_expenses', ['tblmV1sk6qs4QbUWU' => null]],
            ['app6ZZgY9nHX8mMih', 'office_per_job', ['tblDaxgxfcaUBeQPT' => null]],
            ['app6ZZgY9nHX8mMih', 'weekly_data', ['tblzC1laiwxmLw50S' => null]],
            ['app6ZZgY9nHX8mMih', 'companies', ['tblC2qB7ew6in1YTC' => null]],
            ['app6ZZgY9nHX8mMih', 'monthly_bills', ['tbl0xxoIuToQWIYmN' => null]],
            ['app6ZZgY9nHX8mMih', 'lease_reviews', ['tblQRWVs4Xz53H8Xf' => null]],
        ];
        // One shared table per kind, one row per company it is filtered to.
        $shared = ['tblxCKagahNfmjaSQ' => 'refunds', 'tblxkLPCe759GoPzn' => 'disputes', 'tblpTKRRhUrirBRpy' => 'check_returns'];
        $companies = ['NJ', 'CT', 'TX', 'OH', 'IL', 'GA', 'DMV', 'WA', 'NW', 'NY', 'MA'];

        foreach ($orgs as $org) {
            $rows = [];
            $row = fn ($base, $table, $role, $company, $field = null, $value = null) => [
                'id' => (string) Str::uuid(), 'organization_id' => $org, 'base_id' => $base, 'table_id' => $table, 'role' => $role,
                'company' => $company, 'filter_field' => $field, 'filter_value' => $value, 'fields' => null, 'created_at' => now(), 'updated_at' => now(),
            ];
            foreach ($perCompany as [$base, $role, $tables]) {
                foreach ($tables as $table => $company) {
                    $rows[] = $row($base, $table, $role, $company);
                }
            }
            foreach ($shared as $table => $role) {
                foreach ($companies as $code) {
                    $rows[] = $row('appe1vSSRRvKToShq', $table, $role, $code, 'Company Name', 'A1 CHIMNEY '.$code);
                }
            }
            DB::table('report_sources')->insert($rows);

            // Its branches, by the names it gave them (what PeriodReport matched until now).
            $names = ['New Jersey' => 'NJ', 'Connecticut' => 'CT', 'Texas' => 'TX', 'Ohio' => 'OH', 'Illinois' => 'IL', 'Georgia' => 'GA',
                'DMV' => 'DMV', 'Washington' => 'WA', 'Nationwide' => 'NW', 'New York' => 'NY', 'Massachusetts' => 'MA'];
            foreach (DB::table('franchises')->where('organization_id', $org)->whereIn('name', array_keys($names))->get(['id', 'name']) as $f) {
                DB::table('report_companies')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org, 'franchise_id' => $f->id,
                    'code' => $names[$f->name], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
};
