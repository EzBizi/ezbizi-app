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
 * Staff on the phones are told their calls are scored (Jose, 2026-10-12, via
 * Grok AI: "Contract clause + one notice"). A new version of each phone
 * contract with one paragraph closing its COMPUTER AND ACTIVITY MONITORING
 * section; nothing else changes. Onboardings under way keep their pinned
 * version. Run it again and it changes nothing.
 */
class CallScoringClause extends Command
{
    protected $signature = 'hr:call-scoring-clause
                            {organization : The organization name}
                            {--dry-run : Say what would change without writing}';

    protected $description = 'Tell phone staff, in their contract, that calls are recorded and AI-scored';

    /** The contracts whose people answer the phones. */
    public const CONTRACTS = ['Call Center Employee', 'Customer Relations', 'Dispatch Supervisor'];

    public const CLAUSE = 'Call Recording and AI Review: Calls the Employee makes or receives on Company phone lines are recorded and transcribed. The recordings and transcripts are assessed by an artificial intelligence service (xAI\'s Grok) to score how each call was handled and to produce coaching notes. The scores and coaching notes are visible to the Employee\'s managers and are included in performance reports.';

    /** The body with the clause, or null when it has no monitoring section to carry it. */
    public static function body(string $body): ?string
    {
        $body = str_replace("\r\n", "\n", $body);
        if (str_contains($body, self::CLAUSE)) {
            return $body;
        }
        if (! preg_match('/^\d+\.\s+COMPUTER AND ACTIVITY MONITORING\s*$/m', $body, $head, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $head[0][1] + strlen($head[0][0]);
        $end = preg_match('/^\d+\.\s+[A-Z][A-Z &\/,\'-]+\s*$/m', substr($body, $start), $next, PREG_OFFSET_CAPTURE)
            ? $start + $next[0][1] : strlen($body);

        return rtrim(substr($body, 0, $end))."\n\n".self::CLAUSE."\n\n".ltrim(substr($body, $end));
    }

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $organization = Organization::query()->where('name', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error('No organization by that name.');

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($audit) {
            $author = User::query()->with('role')->get()->first(fn (User $u) => $u->isAdministrator());
            $plans = [];

            $contracts = Contract::query()->whereNull('archived_at')->whereIn('name', self::CONTRACTS)
                ->with('currentVersion')->orderBy('name')->get();
            foreach ($contracts as $contract) {
                $current = $contract->currentVersion;
                $body = $current === null ? null : self::body((string) $current->body);
                if ($body === null) {
                    $this->line("{$contract->name}: no monitoring section, left as it is");

                    continue;
                }
                if ($body === str_replace("\r\n", "\n", (string) $current->body)) {
                    $this->line("{$contract->name}: already says so");

                    continue;
                }
                $this->line(sprintf('%s: call scoring clause as version %d', $contract->name,
                    (int) $contract->versions()->max('version') + 1));
                $plans[] = [$contract, $current, $body];
            }

            if ($this->option('dry-run')) {
                $this->info(count($plans).' contracts would change. Dry run. Nothing written.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($plans, $author, $audit) {
                foreach ($plans as [$contract, $current, $body]) {
                    $version = ContractVersion::create([
                        'contract_id' => $contract->getKey(),
                        'version' => (int) $contract->versions()->max('version') + 1,
                        'fields' => $current->fieldSet()->toArray(),
                        'body' => $body,
                        'notes' => 'Calls are recorded and scored by AI for coaching.',
                        'created_by' => $author?->getKey(),
                        'cover_source' => $current->cover_source,
                        'cover_path' => $current->cover_path,
                        'cover_upload_id' => $current->cover_upload_id,
                        'cover_filename' => $current->cover_filename,
                    ]);
                    $contract->forceFill(['current_version_id' => $version->getKey()])->save();
                    $audit->log('contract.version_published', $contract, metadata: ['change' => 'call_scoring_clause'], actor: $author);
                }
            });

            $this->info(count($plans).' contracts given a new version.');

            return self::SUCCESS;
        });
    }
}
