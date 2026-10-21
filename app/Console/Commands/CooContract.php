<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A1 Chimney's Chief Operating Officer offer (Jose, 2026-10-21: "adapt and
 * add this new contract for a-1chimney"), from the offer signed 2026-10-09,
 * with the person's details replaced by the form's own questions. Its
 * paperwork questions (tax, bank, licence, I-9, signatures) are the
 * organization's Customer Relations contract's, so it onboards like every
 * other; of the pay questions only the salary is asked, because the rest of
 * this role's pay is written into the offer. Run again and it does nothing.
 */
class CooContract extends Command
{
    protected $signature = 'hr:coo-contract
                            {organization : The organization name}
                            {--dry-run : Say what would be created without writing}';

    protected $description = 'Add the Chief Operating Officer offer, with Customer Relations\' paperwork questions';

    public const NAME = 'Chief Operating Officer (COO)';

    /**
     * This offer's own terms, each the employer's to fill in, mark "To be
     * determined" or turn off (which drops its section) on the onboarding
     * (Jose, 2026-10-21: "should all be empty fields that are filled on the
     * onboarding").
     */
    public const OWN_FIELDS = [
        ['incentive_year', 'Incentive year', 'text'],
        ['incentive_division', 'Incentive division (e.g. A1 Roofing)', 'text'],
        ['incentive_pct', 'Incentive percent of division revenue', 'number'],
        ['incentive_minimum', 'Guaranteed minimum incentive', 'number'],
        ['perf_comp_year', 'Performance plan starts (year)', 'text'],
        ['perf_plan_due', 'Business plan provided by', 'date'],
        ['perf_comp_minimum', 'Minimum performance compensation', 'number'],
        ['pto_days', 'Paid PTO days a year', 'number'],
        ['paid_holidays', 'Paid holidays observed', 'text'],
    ];

    /** Customer Relations questions this offer does not ask: its pay and hours are in the text. */
    public const NOT_ASKED = [
        'pay_basis', 'comp_method', 'hourly_rate', 'daily_deduction', 'commission_pct', 'commission_stages',
        'raise_amount', 'raise_period', 'lunch_break', 'county', 'state', 'uniform_photo',
    ];

    public const BODY = <<<'TXT'
This Offer of Employment ("Agreement") is entered into between:

Company: {{organization.name}}
Company Address: {{organization.address}}
Employee Name: {{first_name}} {{last_name}}
Employee Address: {{address}}

1. EMPLOYMENT DETAILS

Date of Hire: {{date_of_hire}}
Job Title: {{job_title}}

Employment Type: Full-Time, W-2 Employee

Base Salary: ${{salary_amount}} per {{salary_period}}, plus performance-based compensation.

Dear {{first_name}},

{{organization.name}} is pleased to offer you full-time employment as Chief Operating Officer (COO), reporting directly to ownership. In this role, you will take executive ownership of day-to-day company operations and help translate the company's vision into disciplined execution, measurable performance, and scalable growth.

2. THE ROLE

This is an executive operating role for a leader who manages leaders, drives accountability, understands the numbers, and knows how to grow a business. The COO will be expected to connect people, systems, departments, and markets so that strategy becomes execution.

3. CORE RESPONSIBILITIES

• Oversee the company's day-to-day operations across all markets and service lines.
• Manage, coach, and hold General Managers and department leaders accountable for performance and execution.
• Review company KPIs weekly, identify gaps quickly, and ensure corrective actions are implemented.
• Drive revenue growth, profitability, operational efficiency, and disciplined cost management.
• Provide executive oversight of marketing, sales, dispatch, client relations, field operations, and supporting functions.
• Build stronger managers and teams through coaching, performance management, clear expectations, and follow-through.
• Identify operational problems, create practical solutions, assign ownership, and ensure completion.
• Build and improve systems, processes, reporting, and accountability structures as the company scales.
• Help lead the expansion of new services, divisions, and geographic markets.
• Work directly with ownership to convert the company's vision and growth goals into an actionable operating plan.

4. LEADERSHIP EXPECTATIONS

• Operate with a high-energy, ambitious, "make it happen" mindset.
• Lead with both accountability and personality while maintaining a strong performance culture.
• Use revenue, margin, KPIs, forecasting, and other operating data to make decisions.
• Bring strong sales and business-growth instincts to operational decisions.
• Take ownership from A to Z rather than simply managing assigned tasks.

5. WORK SCHEDULE & AVAILABILITY

Regular working hours are {{work_days}}, {{work_hours}}, at the Company's headquarters located at {{organization.address}}. As a senior executive, you must also remain reasonably available after regular business hours when urgent operational matters or emergencies require executive attention.

6. BASE COMPENSATION

Your base salary will be ${{salary_amount}} per {{salary_period}}, paid through the Company's regular payroll practices and subject to applicable payroll deductions and withholdings. This position is structured as full-time W-2 employment.

{{#incentive_pct}}7. {{incentive_year}} {{incentive_division}} INCENTIVE

For calendar year {{incentive_year}}, you will be eligible for an incentive equal to {{incentive_pct}}% of gross revenue generated by the {{incentive_division}} division during {{incentive_year}}, if any. The Company guarantees a minimum incentive payment of ${{incentive_minimum}} for {{incentive_year}} under this provision. If the calculated {{incentive_pct}}% incentive is less than ${{incentive_minimum}}, the Company will pay the difference so that the total {{incentive_year}} incentive equals at least ${{incentive_minimum}}. This incentive is separate from the performance-based compensation plan.{{/incentive_pct}}

{{#perf_comp_minimum}}8. {{perf_comp_year}}+ PERFORMANCE-BASED COMPENSATION

Beginning in calendar year {{perf_comp_year}}, the Company will establish a performance-based compensation plan tied to the business plan developed with you, including agreed performance and business-growth objectives. The {{perf_comp_year}} business plan and written commission structure, including the applicable KPIs, measurement methodology, and payment terms, will be provided no later than {{perf_plan_due}}. The plan will provide for a minimum of ${{perf_comp_minimum}} in total annual performance-based compensation for {{perf_comp_year}}, with additional earning potential based on results.

Performance-based compensation for {{perf_comp_year}} will be measured and paid quarterly based on the performance and growth targets in the business plan. If the total performance-based compensation earned or paid for the first three quarters and the fourth-quarter calculation is less than ${{perf_comp_minimum}}, the Company will make a fourth-quarter adjustment to bring the total {{perf_comp_year}} performance-based compensation to ${{perf_comp_minimum}}. Amounts above that minimum will be determined under the written plan. All payments are subject to applicable payroll withholdings.

Earned commissions and performance-based compensation remain payable if employment ends, whether the separation is voluntary or involuntary. Termination of employment will not result in forfeiture of amounts earned through the termination date under the applicable written plan. Any such unpaid earned amounts will be calculated and paid on the applicable scheduled payment date, or earlier if required by law.{{/perf_comp_minimum}}

9. PAID TIME OFF & HOLIDAYS

You will be eligible for {{pto_days}} paid PTO days annually, administered in accordance with Company policy.{{#paid_holidays}} In addition, the position will observe {{paid_holidays}} as paid days off. The Company will communicate the applicable holiday calendar.{{/paid_holidays}}

10. THE OPPORTUNITY

{{organization.name}} is a rapidly growing national company expanding across multiple markets and service lines. The Company has built marketing capabilities, infrastructure, teams, and a clear vision; the COO's mandate is to connect those pieces, raise the level of execution, and help build the next stage of the organization. As the Company grows, the scope and opportunity of this role are expected to grow with it.

11. EMPLOYMENT TERMS

Employment with {{organization.name}} is at will. This means that either you or the Company may terminate the employment relationship at any time, with or without cause or advance notice, subject to applicable law. This offer is contingent upon completion of any Company-required onboarding documentation and verification of authorization to work in the United States. This letter summarizes the principal terms of the offer and does not create employment for a fixed term.

12. ACCEPTANCE

We are excited about the possibility of you joining {{organization.name}} and bringing your operating experience, leadership, and growth mindset to the organization. If you accept this offer, please sign below.
TXT;

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $organization = Organization::query()->where('name', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error('No organization by that name.');

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($organization, $audit) {
            if (Contract::query()->where('name', self::NAME)->whereNull('archived_at')->exists()) {
                $this->line(self::NAME.': already there');

                return self::SUCCESS;
            }
            $base = Contract::query()->where('name', 'Customer Relations')->whereNull('archived_at')
                ->with('currentVersion')->first()?->currentVersion;
            if ($base === null) {
                $this->error('No Customer Relations contract to take the paperwork questions from. Nothing written.');

                return self::FAILURE;
            }
            $fields = array_values(array_filter($base->fieldSet()->toArray(),
                fn (array $f) => ! in_array($f['key'] ?? '', self::NOT_ASKED, true)));
            // After the pay questions, before the paperwork.
            $at = collect($fields)->search(fn (array $f) => ($f['key'] ?? '') === 'salary_period');
            $at = $at === false ? count($fields) : $at + 1;
            array_splice($fields, $at, 0, array_map(fn (array $f) => [
                'key' => $f[0], 'label' => $f[1], 'type' => $f[2], 'filled_by' => 'employer', 'required' => true,
            ], self::OWN_FIELDS));

            $this->line(self::NAME.': new contract, version 1, '.count($fields).' questions');
            if ($this->option('dry-run')) {
                $this->info('Dry run. Nothing written.');

                return self::SUCCESS;
            }

            $author = User::query()->with('role')->get()->first(fn (User $u) => $u->isAdministrator());
            DB::transaction(function () use ($organization, $base, $fields, $author, $audit) {
                $contract = Contract::create([
                    'organization_id' => $organization->getKey(),
                    'name' => self::NAME,
                    'created_by' => $author?->getKey(),
                ]);
                $version = ContractVersion::create([
                    'contract_id' => $contract->getKey(),
                    'version' => 1,
                    'fields' => $fields,
                    'body' => str_replace("\r\n", "\n", self::BODY),
                    'notes' => 'From the COO offer signed 2026-10-09, made reusable.',
                    'created_by' => $author?->getKey(),
                    'cover_source' => $base->cover_source,
                    'cover_path' => $base->cover_path,
                    'cover_upload_id' => $base->cover_upload_id,
                    'cover_filename' => $base->cover_filename,
                ]);
                $contract->forceFill(['current_version_id' => $version->getKey()])->save();
                $audit->log('contract.version_published', $contract, metadata: ['change' => 'a1_coo'], actor: $author);
            });

            $this->info(self::NAME.' contract created.');

            return self::SUCCESS;
        });
    }
}
