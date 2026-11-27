<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\SummarizesBulkAttributes;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\TrainingTestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A run of multiple-choice questions, optionally tied to a guide.
 *
 * Questions are a JSON array rather than a table — see the migration for
 * why. `correct` lives inside that same JSON; the Learn read path strips it
 * before the response leaves the server (spec §3).
 */
class TrainingTest extends Model implements StatesRetention, SummarizesBulkAttributes
{
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES): filed where its company and branch are. */
    protected static string $changeType = 'training';


    /** @use HasFactory<TrainingTestFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = [
        'company_id', 'franchise_id', 'guide_id', 'title', 'status', 'questions',
        'requires_guide', 'pass_mark', 'max_attempts', 'valid_for_days', 'count_attempts_after_pass',
    ];

    protected $casts = [
        'questions' => 'array',
        'requires_guide' => 'bool',
        // Fifth-option brief (2026-09-09): true is today's behaviour, every
        // attempt counts. false (the default) excludes a retake taken while
        // an earlier pass for the same test is still valid — see
        // `LearnController::submit` and `LapsedPasses`.
        'count_attempts_after_pass' => 'bool',
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Authored content, not a record of anybody. A test is deleted when its '
            .'organization deletes it; ageing one out would silently remove training '
            .'people are still recorded as having passed.'
        );
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TrainingGuide::class, 'guide_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TrainingAssignment::class, 'test_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TrainingAttempt::class, 'test_id');
    }

    public function questionCount(): int
    {
        return count($this->questions ?? []);
    }

    /**
     * `questions` carries the answer key — see this class's own docblock,
     * "`correct` lives inside that same JSON". `AuditLogger::logChange`
     * asks this so `test.updated` records that it changed, and by how
     * many questions, never the questions (or their answers) themselves —
     * see {@see \App\Support\Audit\SummarizesBulkAttributes}'s own docblock.
     */
    public function bulkAuditAttributes(): array
    {
        return ['questions'];
    }
}
