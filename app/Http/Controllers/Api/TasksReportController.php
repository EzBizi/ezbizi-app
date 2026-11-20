<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Reports\ReportView;
use App\Support\Tasks\TaskBoards;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/tasks[?from&to]
 *
 * Task performance (Shoval's list, asked by Task Board 2026-11-20): one row per
 * owner (task_assignees), top-level tasks only, on the boards the viewer can
 * see (TaskBoards::visibleTo, which follows the company and branch in view).
 * Open: not completed. Done: completed in the dates. Overdue: open and due
 * before today. Average days to done: created to completed, over the dates'
 * done tasks. The dates default to this week, on the organization's clock.
 * A task with nobody on it is the row with no user.
 */
class TasksReportController extends Controller
{
    public function show(Request $request, TaskBoards $boards): JsonResponse
    {
        $q = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $zone = ReportView::zone();
        $now = CarbonImmutable::now($zone);
        $from = CarbonImmutable::parse($q['from'] ?? $now->startOfWeek()->toDateString(), $zone)->startOfDay();
        $to = CarbonImmutable::parse($q['to'] ?? $now->endOfWeek()->toDateString(), $zone)->endOfDay();
        // completed_at and created_at are stored in UTC; due_on is a calendar day.
        $range = [$from->utc()->toDateTimeString(), $to->utc()->toDateTimeString()];

        $rows = DB::table('tasks as t')->leftJoin('task_assignees as a', 'a.task_id', '=', 't.id')
            ->where('t.organization_id', app(TenantContext::class)->idOrFail())
            ->whereNull('t.deleted_at')->whereNull('t.parent_task_id')
            ->whereIn('t.board_id', $boards->visibleTo($request->user())->select('id')->toBase())
            ->groupBy('a.user_id')
            ->selectRaw('a.user_id, sum(t.completed_at is null) as open, sum(t.completed_at is null and t.due_on < ?) as overdue, '
                .'sum(t.completed_at between ? and ?) as done, '
                .'avg(case when t.completed_at between ? and ? then timestampdiff(second, t.created_at, t.completed_at) end) as seconds',
                [$now->toDateString(), ...$range, ...$range])
            ->get()
            ->map(fn (object $r) => ['user_id' => $r->user_id, 'open' => (int) $r->open, 'done' => (int) $r->done, 'overdue' => (int) $r->overdue,
                'avg_days_to_done' => $r->seconds === null ? null : round($r->seconds / 86400, 1)])
            ->filter(fn (array $r) => $r['open'] + $r['done'] > 0);

        $names = User::query()->withoutGlobalScopes()->whereIn('id', $rows->pluck('user_id')->filter()->all())->pluck('name', 'id');
        $rows = $rows->map(fn (array $r) => ['name' => $r['user_id'] === null ? null : ($names[$r['user_id']] ?? null)] + $r)
            ->sortBy([['overdue', 'desc'], ['open', 'desc'], ['name', 'asc']])->values();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            // A task with two owners is on both their rows, so these are tasks, not the rows' sum.
            'totals' => array_map('intval', (array) DB::table('tasks as t')->where('t.organization_id', app(TenantContext::class)->idOrFail())
                ->whereNull('t.deleted_at')->whereNull('t.parent_task_id')
                ->whereIn('t.board_id', $boards->visibleTo($request->user())->select('id')->toBase())
                ->selectRaw('cast(coalesce(sum(t.completed_at is null), 0) as unsigned) as open, '
                    .'cast(coalesce(sum(t.completed_at is null and t.due_on < ?), 0) as unsigned) as overdue, '
                    .'cast(coalesce(sum(t.completed_at between ? and ?), 0) as unsigned) as done', [$now->toDateString(), ...$range])
                ->first()),
        ]]);
    }
}
