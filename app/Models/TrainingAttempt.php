<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One graded run at a test.
 *
 * Minimal model — Task 2 adds the §9 personal-data declarations
 * (docs/superpowers/specs/2026-08-28-training-module-design.md).
 */
class TrainingAttempt extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = [
        'user_id', 'test_id', 'answers', 'score', 'passed', 'taken_at', 'expires_on',
    ];

    protected $casts = [
        'answers' => 'array',
        'passed' => 'bool',
        'taken_at' => 'datetime',
        'expires_on' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(TrainingTest::class, 'test_id');
    }
}
