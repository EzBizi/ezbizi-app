<?php

namespace App\Support\Tasks;

use App\Models\Franchise;
use App\Models\TaskBoard;
use App\Models\TaskStatus;
use Illuminate\Database\Eloquent\Builder;

/** Status names across boards, for the Automations builder's pickers. */
class TaskStatuses
{
    /**
     * Distinct status names on the boards of one place in the current tenant.
     * organization = every board; company = its board and its franchises'
     * boards; franchise = its board.
     *
     * @return string[]
     */
    public static function namesIn(string $scopeType, ?string $scopeId): array
    {
        $boards = TaskBoard::query()->select('id');

        match ($scopeType) {
            'organization' => null,
            'company' => $boards->where(fn (Builder $q) => $q
                ->where(fn (Builder $c) => $c->where('kind', TaskBoard::KIND_COMPANY)->where('company_id', $scopeId))
                ->orWhere(fn (Builder $f) => $f->where('kind', TaskBoard::KIND_FRANCHISE)
                    ->whereIn('franchise_id', Franchise::query()->where('company_id', $scopeId)->select('id')))),
            'franchise' => $boards->where('kind', TaskBoard::KIND_FRANCHISE)->where('franchise_id', $scopeId),
            default => throw new \InvalidArgumentException("Unknown scope type '{$scopeType}'."),
        };

        return TaskStatus::query()->whereIn('board_id', $boards)
            ->orderBy('position')->orderBy('name')
            ->pluck('name')
            ->unique(fn (string $n) => mb_strtolower($n))
            ->values()->all();
    }
}
