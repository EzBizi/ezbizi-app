<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\SummarizesBulkAttributes;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One Guidelines card: a situation and the steps to handle it (Jose,
 * 2026-10-14). The business's own reference material, like AssistantEntry,
 * so StatesRetention and not HoldsPersonalData.
 */
class Guideline extends Model implements StatesRetention, SummarizesBulkAttributes
{
    use BelongsToTenant;
    use HasUuids;

    public const MAX_TITLE = 120;

    public const MAX_SUMMARY = 255;

    public const MAX_STEPS = 50;

    public const MAX_STEP = 2000;

    public const MAX_NOTES = 10000;

    protected $table = 'guidelines';

    protected $fillable = [
        'company_id', 'franchise_id',
        'title', 'summary', 'color', 'steps', 'notes', 'position',
    ];

    protected function casts(): array
    {
        return ['steps' => 'array', 'position' => 'integer'];
    }

    public function bulkAuditAttributes(): array
    {
        return ['steps', 'notes'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A guideline the business wrote for its team - what to do in a situation. '
            .'Reference material holding nothing about a person. Kept while the business '
            .'keeps it; deleting the guideline or the organization removes it.'
        );
    }
}
