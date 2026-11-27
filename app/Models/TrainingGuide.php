<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\SummarizesBulkAttributes;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\TrainingGuideFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An ordered run of screens somebody reads.
 *
 * Screens are a JSON array rather than a table — see the migration for why.
 */
class TrainingGuide extends Model implements StatesRetention, SummarizesBulkAttributes
{
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES): filed where its company and branch are. */
    protected static string $changeType = 'training';


    /** @use HasFactory<TrainingGuideFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = [
        'company_id', 'franchise_id', 'title', 'summary', 'status', 'screens',
    ];

    protected $casts = ['screens' => 'array'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Authored content, not a record of anybody. A guide is deleted when its '
            .'organization deletes it; ageing one out would silently remove training '
            .'people are still recorded as having passed.'
        );
    }

    public function tests(): HasMany
    {
        return $this->hasMany(TrainingTest::class, 'guide_id');
    }

    public function screenCount(): int
    {
        return count($this->screens ?? []);
    }

    /**
     * `screens` is bulk — a whole guide's worth of authored content — not
     * sensitive. `AuditLogger::logChange` asks this so `guide.updated`
     * records that it changed, and by how many screens, never the
     * content itself — see {@see \App\Support\Audit\SummarizesBulkAttributes}'s
     * own docblock.
     */
    public function bulkAuditAttributes(): array
    {
        return ['screens'];
    }
}
