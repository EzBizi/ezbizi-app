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

    /**
     * The colours Workiz v2 takes for a new tag (it refuses any other, measured
     * 2026-11-10). Workiz's own constraint, not a business choice.
     */
    public const COLORS = ['#0059a0', '#be2c2c', '#e18a2b', '#37d34d', '#edce4c', '#c04d97', '#198218', '#3d1c8b'];

    /**
     * Account-level tag definitions managed from EzBizi (Jobs, PM Settings,
     * 2026-11-10): each call fans out to every Workiz v2 account of the tenant
     * in context, keeps the mirror and the approved standard in step (so the
     * next read sees no drift), and answers per account: 'created' / 'renamed'
     * / 'deleted', 'exists' / 'missing', or Workiz's refusal.
     *
     * @return array<string, string> account name => outcome
     */
    public static function create(string $name, string $color): array
    {
        $name = trim($name);
        $color = strtolower(trim($color));

        if (! in_array($color, self::COLORS, true)) {
            throw new \InvalidArgumentException('Workiz takes only these tag colours: '.implode(', ', self::COLORS).'.');
        }

        $result = self::each(function (SocialConnection $c, array $tags) use ($name, $color) {
            if (self::find($tags, $name) !== null) {
                return 'exists';
            }

            $sent = WorkizV2::send($c, 'POST', '/tags', ['name' => $name, 'color' => $color, 'type' => 'job']);

            if ($sent['refused'] !== null) {
                return $sent['refused'];
            }

            WorkizRecords::land($c, 'tag', [is_array($sent['data']) ? $sent['data'] : ['name' => $name, 'color' => $color]]);

            return 'created';
        });
        self::standard(fn (array $s) => [...array_filter($s, fn ($t) => strcasecmp($t['name'] ?? '', $name) !== 0), ['name' => $name, 'color' => $color]]);

        return $result;
    }

    /** @return array<string, string> */
    public static function rename(string $old, string $new): array
    {
        $old = trim($old);
        $new = trim($new);

        $result = self::each(function (SocialConnection $c, array $tags) use ($old, $new) {
            $tag = self::find($tags, $old);

            if ($tag === null) {
                return 'missing';
            }

            $sent = WorkizV2::send($c, 'PUT', '/tags/'.rawurlencode($tag['id']), ['name' => $new]);

            if ($sent['refused'] !== null) {
                return $sent['refused'];
            }

            WorkizRecords::land($c, 'tag', [['name' => $new] + $tag]);
            self::retag($c, $tag['name'], $new);
            self::retagJobs($c, $tag['name'], $new);

            return 'renamed';
        });
        self::standard(fn (array $s) => array_map(fn ($t) => strcasecmp($t['name'] ?? '', $old) === 0 ? ['name' => $new] + $t : $t, $s));

        return $result;
    }

    /** @return array<string, string> */
    public static function delete(string $name): array
    {
        $name = trim($name);

        $result = self::each(function (SocialConnection $c, array $tags) use ($name) {
            $tag = self::find($tags, $name);

            if ($tag === null) {
                return 'missing';
            }

            $sent = WorkizV2::send($c, 'DELETE', '/tags/'.rawurlencode($tag['id']));

            if ($sent['refused'] !== null) {
                return $sent['refused'];
            }

            WorkizRecord::query()->where('franchise_id', $c->scope_id)->where('type', 'tag')->where('remote_id', $tag['id'])->delete();
            self::retag($c, $tag['name'], null);
            self::retagJobs($c, $tag['name'], null);

            return 'deleted';
        });
        self::standard(fn (array $s) => array_values(array_filter($s, fn ($t) => strcasecmp($t['name'] ?? '', $name) !== 0)));

        return $result;
    }

    /** @return array<string, string> */
    private static function each(callable $do): array
    {
        $out = [];

        foreach (SocialConnection::query()->where('provider', \App\Support\Social\SocialProvider::Workiz->value)->get() as $c) {
            if (! WorkizV2::uses($c)) {
                continue;
            }

            $account = Franchise::query()->find($c->scope_id)?->name ?? $c->display_name;
            $read = WorkizV2::send($c, 'GET', '/tags');
            $out[$account] = $read['refused'] ?? $do($c, array_values(array_filter((array) ($read['data']['data'] ?? []), 'is_array')));
        }

        return $out;
    }

    private static function find(array $tags, string $name): ?array
    {
        foreach ($tags as $t) {
            if (strcasecmp(trim((string) ($t['name'] ?? '')), $name) === 0) {
                return $t;
            }
        }

        return null;
    }

    /** The approved standard follows a change made from EzBizi, so the tab does not flag it. */
    private static function standard(callable $change): void
    {
        $org = \App\Models\Organization::query()->find(app(\App\Support\Tenancy\TenantContext::class)->id());
        $settings = app(\App\Support\Settings\SettingsResolver::class);
        $now = array_values(array_filter((array) $settings->get('workiz.tag_baseline', $org), 'is_array'));

        if ($org !== null && $now !== []) {
            $settings->set('workiz.tag_baseline', $org, array_values($change($now)));
        }
    }

    /** EzBizi's own jobs in the branch: the tag's new name, or none (a spelling change, not a person's choice). */
    private static function retagJobs(SocialConnection $connection, string $old, ?string $new): void
    {
        \App\Models\Job::query()->where('franchise_id', $connection->scope_id)
            ->where('tags', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $old).'%')
            ->chunkById(500, function ($jobs) use ($old, $new) {
                foreach ($jobs as $job) {
                    $tags = array_values(array_filter(array_map(fn ($t) => $t === $old ? $new : $t, (array) $job->tags), fn ($t) => $t !== null));

                    if ($tags !== $job->tags) {
                        $job->tags = $tags;
                        $job->saveQuietly();
                    }
                }
            });
    }

    /** Workiz renames a tag on every job without touching the jobs' modified time; the mirror follows here. */
    private static function retag(SocialConnection $connection, string $old, ?string $new): void
    {
        WorkizJob::query()->where('franchise_id', $connection->scope_id)
            ->where('payload', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $old).'%')
            ->chunkById(500, function ($jobs) use ($old, $new) {
                foreach ($jobs as $job) {
                    $payload = $job->payload;

                    if (is_array($payload['Tags'] ?? null) && in_array($old, $payload['Tags'], true)) {
                        $payload['Tags'] = array_values(array_filter(array_map(fn ($t) => $t === $old ? $new : $t, $payload['Tags']), fn ($t) => $t !== null));
                        $job->forceFill(['payload' => $payload])->save();
                    }
                }
            });
    }
}
