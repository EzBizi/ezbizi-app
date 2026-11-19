<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Reports\Xlsx;
use App\Support\TimeClock\TimeClockPayroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Work Hours › Payroll (Merav via Jose, 2026-11-19). Behind `work_hours.payroll`. */
class TimeClockPayrollController extends Controller
{
    public function __construct(private readonly TimeClockPayroll $payroll) {}

    /** GET /api/v1/hr/time-clock/payroll?week=YYYY-MM-DD (the week yesterday fell in by default) */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            ...$this->payroll->week($request->user(), $this->week($request)),
            'warning_labels' => TimeClockPayroll::WARNINGS,
        ]]);
    }

    /** PUT /api/v1/hr/time-clock/payroll/{user} {week, bonus?, deductions?, note?} */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'week' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'bonus' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'deductions' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $this->payroll->adjust($request->user(), $user, $data['week'] ?? null, array_map(
            fn ($v) => $v === null ? null : (string) $v, array_diff_key($data, ['week' => 1]),
        ));

        return $this->index($request);
    }

    /** POST /api/v1/hr/time-clock/payroll/approve {user_id, week} */
    public function approve(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'string'], 'week' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $this->payroll->approve($request->user(), User::query()->findOrFail($data['user_id']), $data['week'] ?? null);

        return $this->index($request);
    }

    /** POST /api/v1/hr/time-clock/payroll/approve-all {week} */
    public function approveAll(Request $request): JsonResponse
    {
        $data = $request->validate(['week' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $this->payroll->approveAll($request->user(), $data['week'] ?? null);

        return $this->index($request);
    }

    /** GET /api/v1/hr/time-clock/payroll/export?week=… — the week in view as .xlsx */
    public function export(Request $request): Response
    {
        $week = $this->payroll->week($request->user(), $this->week($request));

        return response(Xlsx::write(['Payroll' => self::table($week['rows']), 'Tech expenses' => self::expenses($week['rows'])]), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="payroll-'.$week['week_start'].'.xlsx"',
        ]);
    }

    /**
     * The sheet's rows: a heading, one line a person, a total.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<mixed>>
     */
    public static function table(array $rows): array
    {
        $out = [['Name', 'Branch', 'Role', 'Basis', 'Rate', 'Per', 'Hours', 'Overtime hours', 'Bonus', 'Deductions', 'Tech expenses (reimburse)', 'Weekly pay', 'Warnings', 'Status', 'Approved by', 'Note']];
        foreach ($rows as $r) {
            $out[] = [$r['person']['name'], $r['person']['branch'], $r['person']['role'], $r['basis'], $r['rate'], $r['period'],
                $r['hours'], $r['overtime_hours'], $r['bonus'], $r['deductions'], $r['tech_expenses'], $r['pay'],
                implode('; ', array_map(fn ($w) => TimeClockPayroll::WARNINGS[$w], $r['warnings'])),
                $r['status'] === 'approved' ? 'Approved' : 'Waiting', $r['approved_by']['name'] ?? '', $r['note'] ?? ''];
        }
        $out[] = ['Total', '', '', '', '', '', round(array_sum(array_column($rows, 'hours')), 2), '', '', '',
            round(array_sum(array_column($rows, 'tech_expenses')), 2),
            round(array_sum(array_map(fn ($r) => $r['pay'] ?? 0, $rows)), 2)];

        return $out;
    }

    /**
     * Each tech expense on its own line, so the manager sees what the money is for.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<mixed>>
     */
    public static function expenses(array $rows): array
    {
        $out = [['Name', 'Branch', 'Job', 'Item', 'Quantity', 'Amount', 'Paid']];
        foreach ($rows as $r) {
            foreach ($r['expense_lines'] as $e) {
                $out[] = [$r['person']['name'], $r['person']['branch'], $e['job_number'], $e['item'], $e['quantity'], $e['amount'], substr((string) $e['paid_at'], 0, 10)];
            }
        }

        return $out;
    }

    private function week(Request $request): ?string
    {
        $week = $request->input('week');

        return is_string($week) ? $week : null;
    }
}
