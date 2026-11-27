<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A part a job needs (Jose, 2026-11-10): "order" (item, quantity, price,
 * delivery date) or "stock" (item, quantity, price). Shown summed up in
 * Project Management's Part Status column. No personal data: an item name,
 * numbers, a date, and the supplier business it comes from.
 *
 * The parts selector (Jose, 2026-11-17) added "pickup" (a same-day pickup
 * store), the supplier as picked, where it goes (warehouse, customer,
 * supplier), and when it was ordered and received.
 */
class JobPart extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const KINDS = ['order', 'stock', 'pickup'];

    /** Not received this many days after it was ordered: red, like a lead (Jose, 2026-11-17). */
    public const LATE_AFTER_DAYS = 3;

    protected $fillable = ['organization_id', 'job_id', 'kind', 'item', 'quantity', 'price_minor', 'delivery_on', 'inventory_item_id', 'position',
        'supplier', 'destination', 'ordered_at', 'received_at', 'remind_at', 'remind_note', 'reminded_at', 'late_notified_at',
        'paid_by_user_id', 'paid_at', 'reimbursed_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_minor' => 'integer', 'delivery_on' => 'date', 'position' => 'integer',
            'supplier' => 'array', 'ordered_at' => 'datetime', 'received_at' => 'datetime',
            'remind_at' => 'datetime', 'reminded_at' => 'datetime', 'late_notified_at' => 'datetime',
            'paid_at' => 'datetime', 'reimbursed_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A part is part of its job and goes with it.');
    }

    public function present(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'item' => $this->item, 'quantity' => $this->quantity,
            'price_minor' => $this->price_minor, 'delivery_on' => $this->delivery_on?->toDateString(),
            'inventory_item_id' => $this->inventory_item_id, 'supplier' => $this->supplier, 'destination' => $this->destination,
            'ordered_at' => $this->ordered_at?->toIso8601String(), 'received_at' => $this->received_at?->toIso8601String(),
            'late' => $this->isLate(), 'remind_at' => $this->remind_at?->toIso8601String(), 'remind_note' => $this->remind_note,
            'paid_by_user_id' => $this->paid_by_user_id, 'paid_by' => $this->paidBy?->name, 'expense_minor' => $this->expenseMinor(),
            'paid_at' => $this->paid_at?->toIso8601String(), 'reimbursed_at' => $this->reimbursed_at?->toIso8601String()];
    }

    public function job(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function paidBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /** What the technician is owed for it: price x quantity, or null when not his. */
    public function expenseMinor(): ?int
    {
        return $this->paid_by_user_id === null ? null : (int) $this->price_minor * $this->quantity;
    }

    /**
     * Tech expenses to reimburse (Merav, 2026-11-19), for the weekly payroll
     * sheet: parts a technician paid for, marked in [$from, $to), unreimbursed
     * unless asked. One line each: job, item, amount.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function techExpenses(?string $userId, \DateTimeInterface $from, \DateTimeInterface $to, bool $withReimbursed = false): \Illuminate\Support\Collection
    {
        return self::query()->with(['job:id,number,title', 'paidBy:id,name'])->whereNotNull('paid_by_user_id')
            ->when($userId, fn ($q) => $q->where('paid_by_user_id', $userId))
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->when(! $withReimbursed, fn ($q) => $q->whereNull('reimbursed_at'))
            ->orderBy('paid_at')->get()
            ->map(fn (self $p) => ['id' => $p->id, 'user_id' => $p->paid_by_user_id, 'user' => $p->paidBy?->name,
                'job_id' => $p->job_id, 'job_number' => $p->job?->number, 'item' => $p->item, 'quantity' => $p->quantity,
                'amount_minor' => $p->expenseMinor(), 'paid_at' => $p->paid_at?->toIso8601String(),
                'reimbursed_at' => $p->reimbursed_at?->toIso8601String()])->values();
    }

    /** Ordered or picked up, not received, for three days or more. */
    public function isLate(): bool
    {
        return $this->ordered_at !== null && $this->received_at === null
            && $this->ordered_at->lte(now()->subDays(self::LATE_AFTER_DAYS));
    }
}
