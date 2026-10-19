<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Support\Modules\ModuleRegistry;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Settings › Activity: who did what, newest first (A1 Leads takeover,
 * 2026-10-16 — Rodrigo's Activity tab, built the EzBizi way).
 *
 * Gated on `audit.view` by the route, and scoped to the CURRENT VIEW (§3):
 * standing in a company you see that company's and its branches' events, and
 * the organization-level ones only from the organization view.
 *
 * Never `select *`: `changes` and `metadata` reach hundreds of KB on some rows,
 * and sorting those overflowed MySQL's sort buffer in production on 10-14. The
 * list carries the six short columns a row draws.
 */
class AuditEventsController
{
    private const WINDOWS = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    private const PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window' => ['nullable', 'in:'.implode(',', array_keys(self::WINDOWS))],
            'source' => ['nullable', 'in:user,staff,system'],
            'before' => ['nullable', 'date'],
            // The redesigned Activity tab's Who / module / search filters (UI
            // queue 111). `module` is the action's prefix, and only a key the
            // module registry declares is accepted, so nothing a caller types
            // reaches the LIKE but a name the software itself defines.
            'actor_id' => ['nullable', 'string', 'max:64'],
            'module'   => ['nullable', 'string', Rule::in(array_keys(app(ModuleRegistry::class)->all()))],
            'q'        => ['nullable', 'string', 'max:100'],
        ]);

        $days   = self::WINDOWS[$validated['window'] ?? '7d'];
        $window = $this->inView($request)->where('occurred_at', '>=', now()->subDays($days));

        $rows = (clone $window)
            ->select(['id', 'occurred_at', 'actor_type', 'actor_id', 'actor_label', 'action', 'target_label', 'scope_type', 'scope_id'])
            ->when($validated['before'] ?? null, fn (Builder $q, $before) => $q->where('occurred_at', '<', $before))
            ->when($validated['source'] ?? null, fn (Builder $q, $source) => $q->where('actor_type', $source))
            ->when($validated['actor_id'] ?? null, fn (Builder $q, $actor) => $q->where('actor_id', $actor))
            ->when($validated['module'] ?? null, fn (Builder $q, $module) => $q->where('action', 'like', addcslashes($module, '%_\\').'.%'))
            // % and _ are LIKE's wildcards and \ its escape: searching "50%"
            // means the characters, not "50 followed by anything".
            ->when($validated['q'] ?? null, fn (Builder $q, $text) => $q->where('target_label', 'like', '%'.addcslashes($text, '%_\\').'%'))
            ->orderByDesc('occurred_at')
            ->limit(self::PAGE + 1)
            ->get();

        $more = $rows->count() > self::PAGE;
        $page = $rows->take(self::PAGE);

        // Where each row happened, by name. One query per kind for the whole
        // page, never one per row. Trashed places too: an event outlives the
        // branch it happened at, and a row that loses its "where" when the
        // branch is closed reads as if it happened nowhere. An organization
        // event, or one written before the logger recorded a scope, happened
        // at the organization, and says so by its name.
        $names = [
            'company'   => $this->namesOf(Company::class, $page->where('scope_type', 'company')->pluck('scope_id')),
            'franchise' => $this->namesOf(Franchise::class, $page->where('scope_type', 'franchise')->pluck('scope_id')),
        ];
        $organization = $request->user()->organization->name;

        return response()->json([
            'data' => $page->map(fn (AuditEvent $e) => $this->row($e) + [
                'actor_id' => $e->actor_id,
                'where'    => in_array($e->scope_type, [null, 'organization'], true)
                    ? $organization
                    : ($names[$e->scope_type][$e->scope_id] ?? null),
            ])->values(),
            'meta' => [
                'more'   => $more,
                'actors' => $this->actors($window),
            ],
        ]);
    }

    /**
     * Everybody who did something in this window and view, for the Who picker.
     *
     * Its own query over the view and the window -- never the page's rows,
     * which would offer only the people on the first hundred lines -- taken
     * BEFORE the actor, module and search filters, so picking one person does
     * not empty the picker of everybody else. People only, because "system"
     * is the source filter's job. Two short columns, distinct, capped at 200
     * because it is a picker and not a report. A renamed person's older rows
     * carry the old label, so the pair can repeat an id; the first label wins.
     * The " <email>" AuditLogger writes is cut off: the picker draws a name.
     *
     * @return list<array{id: string, label: string}>
     */
    private function actors(Builder $window): array
    {
        return (clone $window)
            ->where('actor_type', 'user')
            ->whereNotNull('actor_id')
            ->select(['actor_id', 'actor_label'])
            ->distinct()
            ->orderBy('actor_label')
            ->limit(200)
            ->toBase()
            ->get()
            ->unique('actor_id')
            ->map(fn ($r) => [
                'id'    => (string) $r->actor_id,
                'label' => (string) preg_replace('/\s*<[^<>]*>$/', '', (string) $r->actor_label),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, string> id => name */
    private function namesOf(string $model, Collection $ids): array
    {
        $ids = $ids->filter()->unique()->values();

        return $ids->isEmpty()
            ? []
            : $model::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * One event with what changed (UI queue 110, Jose: a row opens to show
     * before and after). One row, so the detail columns are safe to read here;
     * `changes` is already redacted by AuditLogger. 404 outside the view.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $event = $this->inView($request)
            ->select(['id', 'occurred_at', 'actor_type', 'actor_label', 'action', 'target_label', 'changes', 'metadata'])
            ->whereKey($id)
            ->firstOrFail();

        return response()->json(['data' => [
            ...$this->row($event),
            'changes'  => $event->changes,
            'metadata' => $event->metadata,
        ]]);
    }

    /**
     * This organization's events the CURRENT VIEW may see. The one place the
     * rule lives, so the list and a single row cannot disagree.
     */
    private function inView(Request $request): Builder
    {
        $user  = $request->user();
        $scope = ViewScope::forUser($user);
        // Someone who reaches the whole organization also sees, in a company's
        // view, what belongs to no company: the organization's own events, and
        // rows written before the logger recorded a scope (2026-10-19).
        $orgWide = ! $scope->isOrganization() && $user->canActOnOrganization($user->organization);

        return AuditEvent::query()
            ->where('organization_id', $user->organization_id)
            ->when(! $scope->isOrganization(), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where(fn (Builder $c) => $c->where('scope_type', 'company')->whereIn('scope_id', $scope->companyIds()))
                ->orWhere(fn (Builder $f) => $f->where('scope_type', 'franchise')->whereIn('scope_id', $scope->franchiseIds()))
                ->when($orgWide, fn (Builder $o) => $o->orWhereNull('scope_type')->orWhere('scope_type', 'organization'))));
    }

    private function row(AuditEvent $e): array
    {
        return [
            'id'          => $e->id,
            'occurred_at' => $e->occurred_at?->toIso8601String(),
            'source'      => $e->actor_type,
            'actor'       => $e->actor_label,
            'action'      => $e->action,
            'target'      => $e->target_label,
        ];
    }
}
