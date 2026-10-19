<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which website a website lead came from (Jose, 2026-10-19: "Forms not
 * showing which website it came from"). It was written to `summary`, which
 * Grok's one-line read then replaced. Filled from the chat or booking that
 * carries the site, and for a form when its company has one site only;
 * otherwise unknown for the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('site_host', 191)->nullable()->after('summary');
        });

        foreach (['web_chats', 'web_bookings'] as $t) {
            DB::table('leads')->join($t, $t.'.lead_id', '=', 'leads.id')->join('web_sites', 'web_sites.id', '=', $t.'.site_id')
                ->whereNull('leads.site_host')->update(['leads.site_host' => DB::raw('web_sites.host')]);
        }
        $single = DB::table('web_sites')->select('company_id', DB::raw('MIN(host) host'))->groupBy('company_id')->havingRaw('COUNT(*) = 1')->pluck('host', 'company_id');
        foreach ($single as $company => $host) {
            DB::table('leads')->where('company_id', $company)->whereNull('site_host')
                ->whereIn('source', ['website_form', 'website_chat', 'website_booking'])->update(['site_host' => $host]);
        }
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn('site_host'));
    }
};
