<?php

use App\Models\Company;
use App\Models\Organization;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;

/**
 * A-1 Chimney's call-line map, queue and company were constants in
 * RingCentralLeadCalls until 2026-10-16 (Jose: "it all needs to be stablished
 * as a public product would"). They are A-1's settings now, the values the
 * code held, so nothing about how A-1's calls file changes today. Any other
 * business sets its own in Settings › Leads.
 */
return new class extends Migration
{
    public function up(): void
    {
        $org = Organization::withoutGlobalScopes()->where('slug', 'a-1-chimney')->first();
        if ($org === null) {
            return;
        }

        app(TenantContext::class)->run($org, function () use ($org) {
            $settings = app(SettingsResolver::class);
            $settings->set('leads.line_markets', $org, [
                'NJ' => 'New Jersey', 'PHL' => 'New Jersey', 'NJ-PHL' => 'New Jersey',
                'CT' => 'Connecticut',
                'CHI' => 'Illinois', 'IL' => 'Illinois',
                'CLE' => 'Ohio', 'CBUS' => 'Ohio', 'CINCY' => 'Ohio', 'OH' => 'Ohio',
                'ATL' => 'Georgia', 'GA' => 'Georgia',
                'DFW' => 'Texas', 'AUS' => 'Texas', 'SAT' => 'Texas', 'HOU' => 'Texas', 'TX' => 'Texas',
                'MD' => 'DMV', 'VA' => 'DMV', 'DC' => 'DMV', 'DMV' => 'DMV',
                'NY' => 'New York',
                'MA' => 'Massachusetts', 'BOS' => 'Massachusetts',
                'IN' => 'Nationwide', 'OR' => 'Nationwide',
            ]);
            $settings->set('leads.existing_client_queue', $org, 'A1 - Existing Client');
            if (Company::query()->whereKey('019fd712-75d5-71ed-a0e2-1d5ff11bd803')->exists()) {
                $settings->set('leads.calls_company', $org, '019fd712-75d5-71ed-a0e2-1d5ff11bd803');
            }
        });
    }

    public function down(): void
    {
        // Empty on purpose: the business may have edited them since.
    }
};
