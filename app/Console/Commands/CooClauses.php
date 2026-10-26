<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Hr\OnboardingCatalog\FieldLibrary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The COO contract gains the clauses every other A1 agreement has (Jose,
 * 2026-10-26: "include all of it"): monitoring, confidentiality, non-compete
 * and non-solicitation, termination, image, company property, dispute
 * resolution, governing law, severability, entire agreement, word for word
 * from the Project Manager agreement, and the misuse-of-resources clause from
 * the Local Field Manager one. Two adaptations: the termination clause's
 * "upsale bonuses" line (a PM term) speaks of this offer's incentives, and
 * the county and state questions come back because the dispute clause names
 * them. A new version; onboardings already started keep theirs. Run again
 * and it does nothing.
 */
class CooClauses extends Command
{
    protected $signature = 'hr:coo-clauses
                            {organization : The organization name}
                            {--dry-run : Say what would change without writing}';

    protected $description = 'Give the COO contract the standard clauses the other agreements have';

    public const CLAUSES = <<<'TXT'
12. COMPUTER AND ACTIVITY MONITORING

The Employee acknowledges and agrees that the Company reserves the right to monitor, access, and record any activity conducted on company-owned computers, devices, and systems at any time. This includes, but is not limited to, internet usage, software applications, email communications, GPS tracking on company vehicles, and any other digital activity.

The Employee understands that there is no expectation of privacy when using company equipment or networks, and such monitoring may occur without prior notice. All data generated on company equipment remains the property of the Employer.

13. CONFIDENTIALITY

The Employee acknowledges that during the course of employment, the Employee may have access to confidential and proprietary information belonging to the Employer, including but not limited to: customer lists, pricing structures, business strategies, marketing plans, operational procedures, financial data, software systems, and trade secrets (collectively, "Confidential Information").

The Employee agrees to the following:

• Not to disclose, publish, or otherwise share any Confidential Information with any third party during or after employment without the prior written consent of the Employer.
• Not to use any Confidential Information for personal benefit or for the benefit of any third party.
• To return all Confidential Information, documents, materials, keys, equipment, and company property upon termination of employment.
• The obligations set forth in this Section shall survive the termination of this Agreement and remain in effect indefinitely.

14. PROHIBITION OF JOB DIVERSION & MISUSE OF COMPANY RESOURCES

(a) Prohibition of Job Diversion

The Employee is strictly prohibited from diverting, redirecting, or referring any customer, lead, or job opportunity away from {{organization.name}} to themselves personally, to any other employee, or to any third party. All leads, inquiries, and customer relationships generated during the course of employment belong exclusively to the Company.

(b) Prohibition of Theft and Financial Misconduct

The Employee shall not engage in any form of theft, embezzlement, or financial misconduct, including but not limited to:

• Misappropriating customer payments, deposits, or company funds
• Falsifying invoices, time records, customer notes, or any other company documentation
• Issuing unauthorized refunds, discounts, or credits
• Sharing customer payment information outside of authorized payment processing channels

(c) Remedies and Right to Legal Action

The Employee acknowledges that any violation of this Section constitutes a material breach of this Agreement and may result in:

• Immediate termination of employment without notice
• The Company's right to pursue civil litigation against the Employee for the recovery of all damages, lost revenue, and profits resulting from the Employee's unauthorized activities
• The Company's right to seek injunctive relief to prevent ongoing or further violations
• The Company's right to report criminal conduct (including theft, embezzlement, or fraud) to the appropriate law enforcement authorities for criminal prosecution
• Recovery of all reasonable attorneys' fees, court costs, and expenses incurred by the Company in enforcing this Section

15. NON-COMPETE & NON-SOLICITATION

(a) Non-Compete

This provision applies only if the Company has provided training to the Employee. Where it applies, then during the term of employment and for a period of ninety (90) days following the termination of employment (regardless of the reason for termination), the Employee agrees not to, directly or indirectly, engage in, become employed by, consult for, or have any ownership interest in any business that provides chimney, fireplace, or similar home services within a fifty (50) mile radius of any {{organization.name}} operating location where the Employee was assigned.

(b) Non-Solicitation

For a period of one (1) year following termination, the Employee agrees not to:

• Solicit, contact, or attempt to divert any customer or client of the Employer with whom the Employee had contact during employment.
• Solicit, recruit, or encourage any employee of the Employer to leave their employment.

(c) Remedies

The Employee acknowledges that a breach of this Section would cause irreparable harm to the Employer. In the event of a breach or threatened breach, the Employer shall be entitled to seek injunctive relief in addition to any other remedies available at law or in equity, including reasonable attorneys' fees.

16. TERMINATION

(a) At-Will Termination

Either Party may terminate this Agreement at any time, with or without cause. The terminating Party shall provide at least thirty (30) days written notice to the other Party. The Employer reserves the right to provide pay in lieu of notice at its sole discretion.

(b) Immediate Termination

The Employer may terminate the Employee immediately and without notice for any of the following reasons:

• Dishonesty, fraud, theft, or embezzlement
• Gross misconduct or insubordination
• Violation of company policies or safety procedures
• Unauthorized disclosure of Confidential Information
• Conviction of or plea to a felony or crime of moral turpitude
• Failure to perform assigned duties after written warning
• Being under the influence of drugs or alcohol during working hours
• Damage to company property, equipment, or reputation

(c) Final Pay

Upon termination, the Employee shall receive all earned wages and any accrued but unused benefits to the extent required by applicable state law. Earned incentive and performance-based compensation is paid as set out in this Agreement, including after termination.

17. USE OF IMAGE AND LIKENESS

The Employee authorizes the Employer to use the Employee's name, photograph, and likeness for legitimate business purposes, including but not limited to: the company website, internal systems, employee directories, marketing materials, social media, and related business platforms. This authorization remains in effect during and after the term of employment unless revoked in writing by the Employee.

18. COMPANY PROPERTY & EQUIPMENT

Any tools, equipment, vehicles, uniforms, devices, keys, or other property issued to the Employee by the Employer remain the sole property of the Employer and must be returned in good condition (reasonable wear and tear excepted) upon termination of employment. The Employee shall be financially responsible for any loss of or damage to company property caused by negligence or intentional misconduct.

19. DISPUTE RESOLUTION

Any dispute or claim arising out of or relating to this Agreement shall first be submitted to good faith mediation. If mediation is unsuccessful, the dispute shall be resolved by binding arbitration in accordance with the rules of the American Arbitration Association, conducted in {{county}} County, State of {{state}}. The prevailing party in any arbitration or legal proceeding shall be entitled to recover reasonable attorneys' fees and costs.

20. GOVERNING LAW

This Agreement shall be governed by and construed in accordance with the laws of the State of {{state}}, without regard to its conflict of law principles.

21. SEVERABILITY

If any provision of this Agreement is found to be invalid, illegal, or unenforceable by a court of competent jurisdiction, the remaining provisions shall continue in full force and effect. The invalid provision shall be modified to the minimum extent necessary to make it enforceable while preserving the Parties' original intent.

22. ENTIRE AGREEMENT

This Agreement constitutes the entire understanding between the Parties with respect to the Employee's employment and supersedes all prior negotiations, representations, and agreements, whether written or oral. This Agreement may not be modified except by a written instrument signed by both Parties.

23. ACCEPTANCE, ACKNOWLEDGMENT & SIGNATURES
TXT;

    public const CLOSING = 'By signing below, both Parties acknowledge that they have read, understood, and agree to all the terms and conditions set forth in this Agreement.';

    /** The body with the clauses before the acceptance, or null when it is not the COO offer's shape. */
    public static function body(string $body): ?string
    {
        $body = str_replace("\r\n", "\n", $body);
        if (str_contains($body, 'CONFIDENTIALITY')) {
            return $body;
        }
        if (substr_count($body, "12. ACCEPTANCE\n\n") !== 1) {
            return null;
        }

        return rtrim(str_replace("12. ACCEPTANCE\n\n", str_replace("\r\n", "\n", self::CLAUSES)."\n\n", $body))
            ."\n\n".self::CLOSING;
    }

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $organization = Organization::query()->where('name', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error('No organization by that name.');

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($audit) {
            $contract = Contract::query()->where('name', CooContract::NAME)->whereNull('archived_at')
                ->with('currentVersion')->first();
            $current = $contract?->currentVersion;
            $body = $current === null ? null : self::body((string) $current->body);
            if ($body === null) {
                $this->error('No COO contract in the offer\'s shape. Nothing written.');

                return self::FAILURE;
            }
            if ($body === str_replace("\r\n", "\n", (string) $current->body)) {
                $this->line(CooContract::NAME.': already has the clauses');

                return self::SUCCESS;
            }

            // County and state, which the dispute clause names, from Customer
            // Relations as the rest of its paperwork questions were.
            $fields = $current->fieldSet()->toArray();
            $have = array_column($fields, 'key');
            $base = Contract::query()->where('name', 'Customer Relations')->whereNull('archived_at')
                ->with('currentVersion')->first()?->currentVersion;
            $theirs = collect($base?->fieldSet()->toArray() ?? [])->keyBy('key');
            $extra = [];
            foreach (['county', 'state'] as $key) {
                if (! in_array($key, $have, true)) {
                    $extra[] = $theirs->get($key) ?? FieldLibrary::definition($key);
                }
            }
            $at = array_search('tax_id_type', $have, true);
            array_splice($fields, $at === false ? count($fields) : $at, 0, $extra);

            $this->line(sprintf('%s: standard clauses as version %d', CooContract::NAME,
                (int) $contract->versions()->max('version') + 1));
            if ($this->option('dry-run')) {
                $this->info('Dry run. Nothing written.');

                return self::SUCCESS;
            }

            $author = User::query()->with('role')->get()->first(fn (User $u) => $u->isAdministrator());
            DB::transaction(function () use ($contract, $current, $body, $fields, $author, $audit) {
                $version = ContractVersion::create([
                    'contract_id' => $contract->getKey(),
                    'version' => (int) $contract->versions()->max('version') + 1,
                    'fields' => $fields,
                    'body' => $body,
                    'notes' => 'The standard clauses every other agreement has.',
                    'created_by' => $author?->getKey(),
                    'cover_source' => $current->cover_source,
                    'cover_path' => $current->cover_path,
                    'cover_upload_id' => $current->cover_upload_id,
                    'cover_filename' => $current->cover_filename,
                ]);
                $contract->forceFill(['current_version_id' => $version->getKey()])->save();
                $audit->log('contract.version_published', $contract, metadata: ['change' => 'coo_clauses'], actor: $author);
            });

            $this->info(CooContract::NAME.' given the standard clauses.');

            return self::SUCCESS;
        });
    }
}
