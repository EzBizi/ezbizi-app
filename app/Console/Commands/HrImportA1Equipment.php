<?php

namespace App\Console\Commands;

use App\Models\HeldEquipment;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The tools and equipment A1 Tools recorded as held by people, into each
 * person's HR › Equipment (Jose, 2026-11-03: "yes" to moving them over).
 *
 * Reads the file scripts/a1-export-equipment.php writes on the box, so A1 is
 * only ever read. A person is matched by email, then by phone digits; a row
 * nobody matches is reported and skipped, never attached to a guess. Each row
 * keeps its A1 ref, so a second run adds nothing. A1's tool catalogue and
 * stock counts are Inventory's, not this.
 */
class HrImportA1Equipment extends Command
{
    protected $signature = 'hr:import-a1-equipment
        {file : JSON written by scripts/a1-export-equipment.php}
        {--organization= : Organization name, e.g. A1-Group}
        {--dry-run : Report what would be written and write nothing}';

    protected $description = 'Import the tools and equipment A1 Tools recorded as held by people';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $rows = json_decode((string) @file_get_contents((string) $this->argument('file')), true);
        $organization = Organization::query()->where('name', (string) $this->option('organization'))->first();
        if (! is_array($rows) || $organization === null) {
            $this->error(! is_array($rows) ? 'That file is not the export.' : 'No organization by that name.');

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($rows, $organization, $audit) {
            $digits = fn (?string $p) => substr(preg_replace('/\D/', '', (string) $p), -10);
            $people = User::query()->get(['id', 'email', 'phone']);
            $byEmail = $people->filter(fn ($u) => $u->email)->keyBy(fn ($u) => mb_strtolower(trim($u->email)));
            $byPhone = $people->filter(fn ($u) => strlen($digits($u->phone)) === 10)->keyBy(fn ($u) => $digits($u->phone));
            $have = HeldEquipment::query()->whereNotNull('import_ref')->pluck('import_ref')->flip();

            $new = [];
            $already = 0;
            $unmatched = [];
            foreach ($rows as $row) {
                if (isset($have[$row['ref']])) {
                    $already++;

                    continue;
                }
                $user = $byEmail->get(mb_strtolower(trim((string) ($row['email'] ?? ''))))
                    ?? (strlen($digits($row['phone'] ?? '')) === 10 ? $byPhone->get($digits($row['phone'])) : null);
                if ($user === null) {
                    $unmatched[(string) ($row['name'] ?? '?')] = true;

                    continue;
                }
                $new[] = [$user, $row];
            }

            $this->line(sprintf('%d to add, %d already imported, %d rows for %d people with no account here.',
                count($new), $already, count($rows) - count($new) - $already, count($unmatched)));
            foreach (array_keys($unmatched) as $name) {
                $this->line("  no account: {$name}");
            }
            if ($this->option('dry-run')) {
                $this->info('Dry run. Nothing written.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($new, $organization, $audit) {
                foreach ($new as [$user, $row]) {
                    HeldEquipment::create([
                        'organization_id' => $organization->getKey(), 'user_id' => $user->getKey(),
                        'name' => mb_substr((string) $row['item'], 0, 160),
                        'category' => $row['category'] === 'equipment' ? 'equipment' : 'tool',
                        'quantity' => max(1, (int) $row['quantity']),
                        'issued_on' => $row['issued_on'], 'returned_on' => $row['returned_on'],
                        'note' => $row['note'], 'import_ref' => $row['ref'],
                    ]);
                }
                $audit->log('person.equipment_imported', metadata: ['rows' => count($new), 'source' => 'a1_tools']);
            });
            $this->info(count($new).' added.');

            return self::SUCCESS;
        });
    }
}
