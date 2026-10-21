<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkizTeamMember;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Links unlinked Workiz team members to EzBizi people (Jose, 2026-10-21: 157 of
 * 998 linked). By email, else by exact name among the people who reach the
 * member's branch. Exactly one candidate links; more than one is reported and
 * left alone. A row that names a user, or that a person unlinked, is never touched.
 *
 * ponytail: no phone step — Workiz's team payload carries no phone (measured
 * on production 2026-10-21: id,name,role,email,…). Add it if Workiz ever sends one.
 */
class LinkWorkizTeam extends Command
{
    protected $signature = 'workiz:link-team {--dry-run : Report, write nothing}';

    protected $description = 'Link unlinked Workiz team members to EzBizi users by email, then exact name in the branch';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = [['organization', 'franchise', 'member_id', 'member_name', 'member_email', 'outcome', 'by', 'user_ids']];
        $counts = ['email' => 0, 'name' => 0, 'ambiguous' => 0, 'none' => 0];

        foreach (Organization::query()->get() as $org) {
            $tenant->run($org, function () use ($org, $dry, $audit, &$report, &$counts) {
                $users = User::query()->where('is_active', true)->get();
                $franchises = Franchise::query()->get()->keyBy('id');

                $members = WorkizTeamMember::query()->whereNull('user_id')->whereNull('link_confirmed_at')->get();
                foreach ($members as $m) {
                    [$by, $hits] = self::candidates($m, $users, $franchises[$m->franchise_id] ?? null);
                    $outcome = match (count($hits)) { 0 => 'none', 1 => 'linked', default => 'ambiguous' };
                    $counts[$outcome === 'linked' ? $by : $outcome]++;
                    $report[] = [$org->name, $franchises[$m->franchise_id]->name ?? '', $m->id, $m->name, $m->email, $outcome, $by, implode(' ', $hits->pluck('id')->all())];

                    if ($outcome === 'linked' && ! $dry) {
                        $m->forceFill(['user_id' => $hits->first()->id, 'auto_matched' => true])->save();
                        $audit->log('workiz.team_member_linked', $m, ['user_id' => [null, $hits->first()->id]], ['by' => $by, 'source' => 'workiz:link-team']);
                    }
                }
            });
        }

        $path = 'reports/workiz-link-team-'.now()->format('Ymd-His').($dry ? '-dry' : '').'.csv';
        Storage::put($path, implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $r)), $report))."\n");

        $this->info(($dry ? 'Would link ' : 'Linked ').($counts['email'] + $counts['name'])
            ." (email {$counts['email']}, name {$counts['name']}); ambiguous {$counts['ambiguous']}; no match {$counts['none']}. Report: storage/app/{$path}");

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: \Illuminate\Support\Collection} */
    private static function candidates(WorkizTeamMember $m, $users, ?Franchise $franchise): array
    {
        $email = mb_strtolower(trim((string) $m->email));
        if ($email !== '') {
            $hits = $users->filter(fn (User $u) => mb_strtolower(trim((string) $u->email)) === $email)->values();
            if ($hits->isNotEmpty()) {
                return ['email', $hits];
            }
        }

        $name = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $m->name)));
        if ($name === '' || $franchise === null) {
            return ['name', collect()];
        }

        return ['name', $users->filter(fn (User $u) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $u->name))) === $name
            && $u->canActOnFranchise($franchise))->values()];
    }
}
