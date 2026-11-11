<?php

namespace App\Support\Workiz;

use App\Models\Franchise;
use App\Models\Role;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Models\WorkizTagChange;
use App\Support\Automations\EventEmitter;
use App\Support\Notifications\NotificationDispatcher;

/**
 * A branch's Workiz tags against what EzBizi last read (Jose, 2026-11-10: "if
 * someone in the future changes a tag, we know about it and get a warning").
 * Each read of the tag list is compared by tag id: a rename, recolour, new or
 * deleted tag is logged, told to the admins, and raised as an automation event.
 */
final class WorkizTags
{
    /**
     * The changes in this read, before it lands. A first read (nothing held)
     * is no change; a deletion is only seen when the list was read whole.
     *
     * @return list<array{tag: string, change: string, before: ?array, after: ?array}>
     */
    public static function diff(SocialConnection $connection, array $rows, bool $whole): array
    {
        $held = WorkizRecord::query()->where('franchise_id', $connection->scope_id)->where('type', 'tag')->get(['remote_id', 'payload'])
            ->mapWithKeys(fn (WorkizRecord $r) => [$r->remote_id => self::face($r->payload)]);

        if ($held->isEmpty()) {
            return [];
        }

        $changes = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $now = self::face($row);
            $seen[$id] = true;
            $was = $held[$id] ?? null;

            $change = match (true) {
                $id === '' => null,
                $was === null => 'added',
                $was['name'] !== $now['name'] => 'renamed',
                $was['color'] !== $now['color'] => 'recoloured',
                default => null,
            };

            if ($change !== null) {
                $changes[] = ['tag' => $id, 'change' => $change, 'before' => $was, 'after' => $now];
            }
        }

        if ($whole) {
            foreach ($held as $id => $was) {
                if (! isset($seen[$id])) {
                    $changes[] = ['tag' => (string) $id, 'change' => 'deleted', 'before' => $was, 'after' => null];
                }
            }
        }

        return $changes;
    }

    /** Log, follow and tell: renames reach the mirrored jobs, deletions leave the list. */
    public static function record(SocialConnection $connection, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $branch = Franchise::query()->find($connection->scope_id);
        $admins = Role::query()->where('name', Role::ADMINISTRATOR)->pluck('id')->all();

        foreach ($changes as $c) {
            $row = WorkizTagChange::create(['franchise_id' => $connection->scope_id, 'tag_remote_id' => $c['tag'], 'change' => $c['change'],
                'before' => $c['before'], 'after' => $c['after'], 'occurred_at' => now()]);

            if ($c['change'] === 'renamed') {
                self::retag($connection, $c['before']['name'], $c['after']['name']);
            }

            if ($c['change'] === 'deleted') {
                WorkizRecord::query()->where('franchise_id', $connection->scope_id)->where('type', 'tag')->where('remote_id', $c['tag'])->delete();
            }

            $title = 'Workiz tag '.$c['change'].' in '.($branch?->name ?? 'a branch');
            app(NotificationDispatcher::class)->dispatch(title: $title, body: self::sentence($c), subject: $row, source: 'workiz_tag_changed', roleIds: $admins);

            if ($branch !== null) {
                app(EventEmitter::class)->emit('franchise.workiz_tag_changed', $branch, [
                    'change' => $c['change'], 'tag' => $c['tag'], 'before' => $c['before'], 'after' => $c['after'], 'summary' => self::sentence($c),
                ]);
            }
        }
    }

    /** "DEPOSIT RECIEVED -> DEPOSIT RECEIVED" and the like. */
    public static function sentence(array $c): string
    {
        $b = $c['before'];
        $a = $c['after'];

        return match ($c['change']) {
            'renamed' => '"'.$b['name'].'" -> "'.$a['name'].'"',
            'recoloured' => '"'.$a['name'].'" '.$b['color'].' -> '.$a['color'],
            'added' => '"'.$a['name'].'" added',
            default => '"'.$b['name'].'" deleted',
        };
    }

    /** @return array{name: string, color: string} */
    public static function face(array $tag): array
    {
        return ['name' => (string) ($tag['name'] ?? ''), 'color' => strtolower((string) ($tag['color'] ?? ''))];
    }

    /** Workiz renames a tag on every job without touching the jobs' modified time; the mirror follows here. */
    private static function retag(SocialConnection $connection, string $old, string $new): void
    {
        WorkizJob::query()->where('franchise_id', $connection->scope_id)
            ->where('payload', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $old).'%')
            ->chunkById(500, function ($jobs) use ($old, $new) {
                foreach ($jobs as $job) {
                    $payload = $job->payload;

                    if (is_array($payload['Tags'] ?? null) && in_array($old, $payload['Tags'], true)) {
                        $payload['Tags'] = array_map(fn ($t) => $t === $old ? $new : $t, $payload['Tags']);
                        $job->forceFill(['payload' => $payload])->save();
                    }
                }
            });
    }
}
