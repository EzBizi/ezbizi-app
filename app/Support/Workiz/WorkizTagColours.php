<?php

namespace App\Support\Workiz;

use App\Models\WorkizRecord;
use App\Support\Jobs\ProjectCosts;

/**
 * A job tag's Workiz colour (Jose, 2026-11-10: tags in their Workiz colour,
 * not grey). Read from the hourly tag-definition mirror (workiz_records,
 * type tag, payload {name, color}) of the job's own account only. Names
 * compare in any case.
 */
final class WorkizTagColours
{
    /**
     * By franchise id, then lower name. Only the job's own account: never
     * another account's, which can be another company's (Jose, 2026-11-10:
     * "why are everdoors stuff showing up when im on a-1chimney view").
     *
     * @var array<string, array<string, string>>|null
     */
    private ?array $map = null;

    /** The colour Workiz gives PROJECT in every A-1 account. */
    public const PROJECT_COLOUR = '#3D1C8B';

    /** @var array<string, array<string, string>> by franchise id: name as Workiz spells it => colour */
    private array $names = [];

    /**
     * The tags a job's Workiz account has now, as {name, colour}, for the
     * "Add a tag" picker (Jose, 2026-11-10: never offer one the account
     * does not have). Empty for a franchise with no Workiz tag list.
     *
     * @return list<array{name: string, colour: string}>
     */
    public function definitions(?string $franchiseId): array
    {
        $this->map ??= $this->load();

        // EzBizi is master (Jose, 2026-11-25): PROJECT, which gates project costs,
        // is always offered, even where the Workiz list lacks it.
        $names = $this->names[(string) $franchiseId] ?? [];
        if (! collect($names)->keys()->contains(fn ($n) => mb_strtoupper(trim($n)) === ProjectCosts::TAG)) {
            $names[ProjectCosts::TAG] = self::PROJECT_COLOUR;
        }

        return collect($names)
            ->map(fn (string $hex, string $name) => ['name' => $name, 'colour' => $hex])
            ->sortBy(fn ($t) => mb_strtolower($t['name']))->values()->all();
    }

    /** @param  list<string>  $tags @return array<string, string> tag => '#RRGGBB', only those with a colour */
    public function for(?string $franchiseId, array $tags): array
    {
        $this->map ??= $this->load();
        $out = [];
        foreach ($tags as $tag) {
            $key = mb_strtolower(trim((string) $tag));
            $hex = $this->map[(string) $franchiseId][$key] ?? ($key === mb_strtolower(ProjectCosts::TAG) ? self::PROJECT_COLOUR : null);
            if ($hex !== null) {
                $out[(string) $tag] = $hex;
            }
        }

        return $out;
    }

    private function load(): array
    {
        $map = [];
        WorkizRecord::query()->where('type', 'tag')->get(['franchise_id', 'payload'])
            ->each(function (WorkizRecord $r) use (&$map) {
                $p = is_array($r->payload) ? $r->payload : (json_decode((string) $r->payload, true) ?: []);
                $name = mb_strtolower(trim((string) ($p['name'] ?? '')));
                $hex = (string) ($p['color'] ?? '');
                if ($name === '' || ! preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
                    return;
                }
                $map[(string) $r->franchise_id][$name] = strtoupper($hex);
                $this->names[(string) $r->franchise_id][trim((string) $p['name'])] = strtoupper($hex);
            });

        return $map;
    }
}
