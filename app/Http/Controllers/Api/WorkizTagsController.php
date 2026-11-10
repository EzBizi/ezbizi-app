<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WorkizRecord;
use App\Models\WorkizTagChange;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizTags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Every Workiz account's tags side by side, against the approved standard,
 * with the change log (Jose, 2026-11-10: "a sub tab where we can see all
 * existing tags per account ... and get a warning").
 */
class WorkizTagsController
{
    public function index(Request $request, SettingsResolver $settings): JsonResponse
    {
        $this->authorizeIt($request);
        $organization = $this->organization();
        $baseline = collect((array) $settings->get('workiz.tag_baseline', $organization))
            ->filter(fn ($t) => is_array($t) && trim((string) ($t['name'] ?? '')) !== '')
            ->mapWithKeys(fn ($t) => [self::key($t['name']) => WorkizTags::face($t)]);

        // Only accounts whose tags EzBizi reads (v2): a v1 branch holds no tag list,
        // and would show every tag as missing (Everdoors Connecticut, 11-10).
        $branchIds = SocialConnection::query()->where('provider', SocialProvider::Workiz->value)
            ->where('scope_type', SocialConnection::SCOPE_FRANCHISE)->get()
            ->filter(fn (SocialConnection $c) => \App\Support\Workiz\WorkizV2::uses($c))->pluck('scope_id')->unique();
        $accounts = Franchise::query()->whereKey($branchIds)->orderBy('name')->get(['id', 'name']);
        $tags = WorkizRecord::query()->where('type', 'tag')->whereIn('franchise_id', $accounts->pluck('id'))->get(['franchise_id', 'remote_id', 'payload']);

        $keys = $baseline->keys()->merge($tags->map(fn ($t) => self::key($t->payload['name'] ?? '')))->filter()->unique()->sort()->values();
        $rows = $keys->map(function (string $key) use ($baseline, $tags, $accounts) {
            $standard = $baseline[$key] ?? null;
            $cells = [];
            $differs = false;

            foreach ($accounts as $account) {
                $tag = $tags->first(fn ($t) => $t->franchise_id === $account->id && self::key($t->payload['name'] ?? '') === $key);
                $face = $tag === null ? null : WorkizTags::face($tag->payload) + ['id' => $tag->remote_id];
                $cells[$account->id] = $face;
                $differs = $differs || $face === null || ($standard !== null && ($face['name'] !== $standard['name'] || $face['color'] !== $standard['color']));
            }

            return ['key' => $key, 'standard' => $standard, 'in_standard' => $standard !== null, 'differs' => $differs || $standard === null, 'accounts' => $cells];
        })->values();

        $changes = WorkizTagChange::query()->with('franchise:id,name')->latest('occurred_at')->limit(200)->get()
            ->map(fn (WorkizTagChange $c) => ['id' => $c->id, 'account' => $c->franchise?->name, 'franchise_id' => $c->franchise_id, 'change' => $c->change,
                'before' => $c->before, 'after' => $c->after, 'summary' => WorkizTags::sentence(['change' => $c->change, 'before' => $c->before, 'after' => $c->after]),
                'occurred_at' => $c->occurred_at?->toIso8601String()]);

        return response()->json(['data' => [
            'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values(),
            'baseline_set' => $baseline->isNotEmpty(),
            'rows' => $rows,
            'changes' => $changes,
        ]]);
    }

    /** "Approve current tags as standard": each tag's most common spelling and colour across the accounts. */
    public function approve(Request $request, SettingsResolver $settings): JsonResponse
    {
        $this->authorizeIt($request);

        $standard = WorkizRecord::query()->where('type', 'tag')->get(['payload'])
            ->map(fn ($t) => WorkizTags::face($t->payload))->filter(fn ($t) => trim($t['name']) !== '')
            ->groupBy(fn ($t) => self::key($t['name']))
            ->map(fn ($faces) => $faces->countBy(fn ($f) => $f['name']."\0".$f['color'])->sortDesc()->keys()->first())
            ->map(fn (string $pair) => array_combine(['name', 'color'], explode("\0", $pair)))
            ->sortKeys()->values()->all();

        $settings->set('workiz.tag_baseline', $this->organization(), $standard);

        return response()->json(['data' => ['approved' => count($standard)]], Status::HTTP_OK);
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail(app(TenantContext::class)->id());
    }

    private function authorizeIt(Request $request): void
    {
        abort_unless($request->user()->hasPermission(SocialProvider::Workiz->connectPermission()), Status::HTTP_FORBIDDEN,
            \App\Support\Access\Refusal::permission(SocialProvider::Workiz->connectAct())->message());
    }
}
