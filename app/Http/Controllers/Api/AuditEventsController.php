<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditEvent;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);

        $days = self::WINDOWS[$validated['window'] ?? '7d'];

        $rows = $this->inView($request)
            ->select(['id', 'occurred_at', 'actor_type', 'actor_label', 'action', 'target_label', 'scope_type'])
            ->where('occurred_at', '>=', now()->subDays($days))
            ->when($validated['before'] ?? null, fn (Builder $q, $before) => $q->where('occurred_at', '<', $before))
            ->when($validated['source'] ?? null, fn (Builder $q, $source) => $q->where('actor_type', $source))
            ->orderByDesc('occurred_at')
            ->limit(self::PAGE + 1)
            ->get();

        $more = $rows->count() > self::PAGE;

        return response()->json([
            'data' => $rows->take(self::PAGE)->map(fn (AuditEvent $e) => $this->row($e))->values(),
            'meta' => ['more' => $more],
        ]);
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
