<?php

namespace App\Http\Controllers\Api;

use App\Models\EmergencyContact;
use App\Models\HeldEquipment;
use App\Models\HrNote;
use App\Models\PayRate;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * The four lists on a person's record that HR had no home for (Jose,
 * 2026-09-29): pay rates, emergency contacts, HR notes, tools and equipment.
 *
 * One controller rather than four because the four behave the same way — a list
 * of rows belonging to one person, added to, corrected and removed by whoever may
 * edit that person — and differ only in their fields. Reading them is part of the
 * record (HrPeopleController::show), so this is writes only.
 *
 * ── Two gates, and pay has a third ────────────────────────────────
 *
 * `hr.records.view` in front (the route), and UserPolicy::update — `hr.edit`
 * within reach — on the person. Pay additionally needs `hr.pay.edit`: who earns
 * what is a narrower circle than who keeps the people list.
 *
 * ── The audit log records THAT, never WHAT ────────────────────────
 *
 * Each write is an audit event naming the person and the kind of row. None
 * carries the values: a pay rate is sensitive by definition, an emergency
 * contact is a third person's phone number, and a note is whatever HR wrote.
 * The audit table is append-only and erasure cannot reach it, which makes it the
 * worst place to copy any of them.
 */
class HrPersonDetailsController extends Controller
{
    /** kind => [model, what the audit calls it, the act a refusal names] */
    private const KINDS = [
        'pay-rates'          => [PayRate::class, 'pay_rate', 'change pay rates'],
        'emergency-contacts' => [EmergencyContact::class, 'emergency_contact', null],
        'notes'              => [HrNote::class, 'hr_note', null],
        'equipment'          => [HeldEquipment::class, 'equipment', null],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, User $user, string $kind): JsonResponse
    {
        [$model, $name] = $this->authorizeKind($request, $user, $kind);

        $input = $request->validate($this->rules($kind, creating: true));

        $row = $model::create($input + [
            'organization_id' => $user->organization_id,
            'user_id'         => $user->getKey(),
        ] + $this->authorship($kind, $request->user()));

        $this->audit->log("person.{$name}_added", $user, metadata: ['id' => $row->getKey()]);

        return response()->json(['data' => self::present($row)], 201);
    }

    public function update(Request $request, User $user, string $kind, string $id): JsonResponse
    {
        [$model, $name] = $this->authorizeKind($request, $user, $kind);

        $row = $this->find($model, $user, $id);

        $row->fill($request->validate($this->rules($kind, creating: false)));

        if ($row->isDirty()) {
            $fields = array_keys($row->getDirty());
            $row->save();

            $this->audit->log("person.{$name}_changed", $user, metadata: [
                'id'     => $row->getKey(),
                'fields' => $fields,
            ]);
        }

        return response()->json(['data' => self::present($row)]);
    }

    public function destroy(Request $request, User $user, string $kind, string $id): JsonResponse
    {
        [$model, $name] = $this->authorizeKind($request, $user, $kind);

        $row = $this->find($model, $user, $id);
        $row->delete();

        $this->audit->log("person.{$name}_removed", $user, metadata: ['id' => $id]);

        return response()->json(['data' => ['id' => $id]]);
    }

    /**
     * How a row is shown on the record. Static so the record endpoint draws the
     * same shape as the write endpoints return.
     */
    public static function present(Model $row): array
    {
        return match (true) {
            $row instanceof PayRate => [
                'id'           => $row->id,
                'basis'        => $row->basis,
                'amount'       => $row->amount,
                'period'       => $row->period,
                'effective_on' => $row->effective_on?->toDateString(),
                'note'         => $row->note,
            ],
            $row instanceof EmergencyContact => [
                'id'           => $row->id,
                'name'         => $row->name,
                'relationship' => $row->relationship,
                'phone'        => $row->phone,
            ],
            $row instanceof HrNote => [
                'id'         => $row->id,
                'body'       => $row->body,
                'author'     => $row->author?->name,
                'created_at' => $row->created_at?->toIso8601String(),
            ],
            $row instanceof HeldEquipment => [
                'id'          => $row->id,
                'name'        => $row->name,
                'category'    => $row->category,
                'quantity'    => $row->quantity,
                'identifier'  => $row->identifier,
                'issued_on'   => $row->issued_on?->toDateString(),
                'returned_on' => $row->returned_on?->toDateString(),
                'note'        => $row->note,
            ],
        };
    }

    /** @return array{0: class-string<Model>, 1: string} */
    private function authorizeKind(Request $request, User $user, string $kind): array
    {
        abort_unless(isset(self::KINDS[$kind]), Status::HTTP_NOT_FOUND);

        $this->authorize('update', $user);

        [$model, $name, $act] = self::KINDS[$kind];

        if ($kind === 'pay-rates') {
            abort_unless(
                $request->user()->hasPermission('hr.pay.edit'),
                Status::HTTP_FORBIDDEN,
                Refusal::permission($act)->message(),
            );
        }

        return [$model, $name];
    }

    /**
     * The row, and only if it is THIS person's. An id from somebody else's record
     * answers 404 rather than letting a URL edit a row the policy never checked.
     */
    private function find(string $model, User $user, string $id): Model
    {
        return $model::query()
            ->where('user_id', $user->getKey())
            ->whereKey($id)
            ->firstOrFail();
    }

    private function authorship(string $kind, User $actor): array
    {
        return match ($kind) {
            'pay-rates' => ['created_by_user_id' => $actor->getKey()],
            'notes'     => ['author_user_id' => $actor->getKey()],
            default     => [],
        };
    }

    private function rules(string $kind, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return match ($kind) {
            'pay-rates' => [
                'basis'        => [$required, Rule::in(PayRate::BASES)],
                'amount'       => [$required, 'numeric', 'min:0', 'max:99999999'],
                'period'       => [$required, Rule::in(PayRate::PERIODS)],
                'effective_on' => [$required, 'date'],
                'note'         => ['sometimes', 'nullable', 'string', 'max:1000'],
            ],
            'emergency-contacts' => [
                'name'         => [$required, 'string', 'max:120'],
                'relationship' => ['sometimes', 'nullable', 'string', 'max:60'],
                'phone'        => ['sometimes', 'nullable', 'string', 'max:40'],
            ],
            'notes' => [
                'body' => [$required, 'string', 'max:5000'],
            ],
            'equipment' => [
                'name'        => [$required, 'string', 'max:160'],
                'category'    => [$required, Rule::in(HeldEquipment::CATEGORIES)],
                'quantity'    => ['sometimes', 'integer', 'min:1', 'max:100000'],
                'identifier'  => ['sometimes', 'nullable', 'string', 'max:120'],
                'issued_on'   => ['sometimes', 'nullable', 'date'],
                'returned_on' => ['sometimes', 'nullable', 'date'],
                'note'        => ['sometimes', 'nullable', 'string', 'max:1000'],
            ],
        };
    }
}
