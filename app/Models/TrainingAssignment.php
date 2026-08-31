<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guide or test given to somebody, with an optional due date.
 *
 * Minimal model — Task 2 adds the §9 personal-data declarations
 * (docs/superpowers/specs/2026-08-28-training-module-design.md).
 */
class TrainingAssignment extends Model
{
    use HasUuids;
    use BelongsToTenant;

    public const USER = 'user';

    public const ROLE = 'role';

    protected $fillable = [
        'company_id', 'franchise_id', 'guide_id', 'test_id',
        'assignee_type', 'assignee_id', 'due_on', 'assigned_by_user_id',
    ];

    protected $casts = ['due_on' => 'date'];

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TrainingGuide::class, 'guide_id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(TrainingTest::class, 'test_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
