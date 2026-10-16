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
 * The one A1 Tools contract EzBizi did not have (A1 takeover, Jose
 * 2026-10-16 "go ahead"): A1's Recruiter agreement of 2026-09-23. In A1 it is
 * the shared office agreement with its own Section 2 and an applicant-data
 * clause in Confidentiality, so here it is the organization's current
 * Customer Relations contract (same office agreement, pay terms already
 * standard) with those two parts swapped in, word for word from
 * a1tools lib/features/onboarding/contracts/templates/recruiter.dart.
 * The call-scoring paragraph is phone staff's, not a recruiter's, and is left
 * out. Run again and it does nothing.
 */
class RecruiterContract extends Command
{
    protected $signature = 'hr:recruiter-contract
                            {organization : The organization name}
                            {--dry-run : Say what would be created without writing}';

    protected $description = 'Add A1 Tools\' Recruiter contract, built on the Customer Relations one';

    public const NAME = 'Recruiter';

    private const DUTIES = <<<'TXT'
2. POSITION & DUTIES

The Employee is hired as a Recruiter to source, screen, and hire staff across the Company's field and office teams, and to guide successful candidates through hiring and onboarding. The Employee agrees to perform all assigned duties faithfully, diligently, and to the best of the Employee's abilities, and to comply with all company policies, procedures, and standard operating procedures (SOPs).

Job Responsibilities

The Recruiter's responsibilities include, but are not limited to:

• Source candidates for technician, dispatch, and office positions through job boards, referrals, outreach, and community channels
• Write, publish, and maintain job postings across hiring platforms, and keep them current as staffing needs change
• Screen applications and resumes, and conduct initial phone and video interviews
• Coordinate and schedule interviews between candidates and hiring managers, and follow up on outcomes
• Verify work eligibility, and confirm licenses, certifications, and references where a position requires them
• Initiate and track background checks and any pre-employment screening required for the position
• Maintain the candidate pipeline and keep applicant records accurate and up to date
• Guide new hires through the onboarding process and ensure their paperwork is completed correctly and on time
• Track recruiting metrics — including time to fill, source quality, and new-hire retention — and report them to management
• Represent the Company professionally at job fairs, hiring events, and in all candidate communication
• Perform additional recruiting and administrative tasks as reasonably assigned
TXT;

    private const APPLICANTS = <<<'TXT'
(a) Candidate & Applicant Information

The Employee will handle personal information belonging to job applicants — including contact details, employment history, compensation expectations, government-issued identification numbers, and the results of any background or reference check. The Employee shall treat all such information as strictly confidential, access it only as required to perform recruiting duties, never disclose it to any person outside the hiring process, and never use it for any purpose other than evaluating the applicant for employment with the Company. This obligation applies to every applicant, whether or not they are ultimately hired, and continues after the Employee's employment ends.

The Employee shall conduct all hiring activity in compliance with applicable federal, state, and local employment law, including equal employment opportunity and anti-discrimination requirements, and shall not make or communicate any hiring decision on the basis of a protected characteristic.
TXT;

    /** The Recruiter body from a Customer Relations one, or null when its shape is not the office agreement's. */
    public static function body(string $base): ?string
    {
        $body = str_replace("\r\n", "\n", $base);
        $heading = '/^\d+\.\s+[A-Z][A-Z &\/,\'-]+\s*$/m';

        // Section 2 up to its closing "The Employee is expected..." paragraph.
        $start = strpos($body, '2. POSITION & DUTIES');
        $end = strpos($body, 'The Employee is expected to maintain professional conduct');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $body = substr($body, 0, $start).str_replace("\r\n", "\n", self::DUTIES)."\n".substr($body, $end);

        // The applicant clause closes Confidentiality.
        if (! preg_match('/^\d+\.\s+CONFIDENTIALITY\s*$/m', $body, $head, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $from = $head[0][1] + strlen($head[0][0]);
        $next = preg_match($heading, substr($body, $from), $m, PREG_OFFSET_CAPTURE) ? $from + $m[0][1] : strlen($body);
        $body = rtrim(substr($body, 0, $next))."\n\n".str_replace("\r\n", "\n", self::APPLICANTS)."\n\n".ltrim(substr($body, $next));

        $body = str_replace(["\n\n".CallScoringClause::CLAUSE, CallScoringClause::CLAUSE], '', $body);

        return str_replace('Customer Relations Employment Agreement', 'Recruiter Employment Agreement', $body);
    }

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $organization = Organization::query()->where('name', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error('No organization by that name.');

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($organization, $audit) {
            if (Contract::query()->where('name', self::NAME)->whereNull('archived_at')->exists()) {
                $this->line('Recruiter: already there');

                return self::SUCCESS;
            }
            $base = Contract::query()->where('name', 'Customer Relations')->whereNull('archived_at')
                ->with('currentVersion')->first()?->currentVersion;
            $body = $base === null ? null : self::body((string) $base->body);
            if ($body === null) {
                $this->error('No Customer Relations contract in the office agreement\'s shape to build it on. Nothing written.');

                return self::FAILURE;
            }

            $this->line('Recruiter: new contract, version 1, from Customer Relations version '.$base->version);
            if ($this->option('dry-run')) {
                $this->info('Dry run. Nothing written.');

                return self::SUCCESS;
            }

            $author = User::query()->with('role')->get()->first(fn (User $u) => $u->isAdministrator());
            DB::transaction(function () use ($organization, $base, $body, $author, $audit) {
                $contract = Contract::create([
                    'organization_id' => $organization->getKey(),
                    'name' => self::NAME,
                    'created_by' => $author?->getKey(),
                ]);
                $version = ContractVersion::create([
                    'contract_id' => $contract->getKey(),
                    'version' => 1,
                    'fields' => $base->fieldSet()->toArray(),
                    'body' => $body,
                    'notes' => 'A1 Tools\' Recruiter agreement (2026-09-23).',
                    'created_by' => $author?->getKey(),
                    'cover_source' => $base->cover_source,
                    'cover_path' => $base->cover_path,
                    'cover_upload_id' => $base->cover_upload_id,
                    'cover_filename' => $base->cover_filename,
                ]);
                $contract->forceFill(['current_version_id' => $version->getKey()])->save();
                $audit->log('contract.version_published', $contract, metadata: ['change' => 'a1_recruiter'], actor: $author);
            });

            $this->info('Recruiter contract created.');

            return self::SUCCESS;
        });
    }
}
