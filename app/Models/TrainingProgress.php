<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How far one user has gotten through one guide.
 *
 * Minimal model — Task 2 adds the §9 personal-data declarations
 * (docs/superpowers/specs/2026-08-28-training-module-design.md).
 */
class TrainingProgress extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'user_id', 'guide_id', 'last_screen', 'completed_at',
    ];

    protected $casts = ['completed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TrainingGuide::class, 'guide_id');
    }
}
