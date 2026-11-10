<?php

namespace App\Support\Workiz;

use App\Models\WorkizRecord;

/**
 * A job tag's Workiz colour (Jose, 2026-11-10: tags in their Workiz colour,
 * not grey). Read from the hourly tag-definition mirror (workiz_records,
 * type tag, payload {name, color}): the job's own franchise first, then the
 * same name on any account of the organization. Names compare in any case.
 */
final class WorkizTagColours
{
    /** @var array<string, array<string, string>>|null by franchise id, then lower name; '*' is any */
    private ?array $map = null;

    /** @param  list<string>  $tags @return array<string, string> tag => '#RRGGBB', only those with a colour */
    public function for(?string $franchiseId, array $tags): array
    {
        $this->map ??= $this->load();
        $out = [];
        foreach ($tags as $tag) {
            $key = mb_strtolower(trim((string) $tag));
            $hex = $this->map[(string) $franchiseId][$key] ?? $this->map['*'][$key] ?? null;
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
                $map['*'][$name] ??= strtoupper($hex);
            });

        return $map;
    }
}
