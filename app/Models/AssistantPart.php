<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One part the Tech Assistant may name, with where that came from. A part
 * number the assistant says that is not a row here (or on a manual page) is
 * treated as a guess - see TechAssistant's hallucination guard.
 */
class AssistantPart extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUSES = ['confirmed', 'high_confidence', 'possible', 'unverified', 'needs_review', 'discontinued'];

    public const SOURCES = ['manual', 'manufacturer', 'supplier', 'tech_correction'];

    public const FUELS = ['NG', 'LP', 'both'];

    /** Sources good enough to order from (ORDER CHECK). */
    public const ORDERABLE_SOURCES = ['manual', 'manufacturer'];

    protected $table = 'assistant_parts';

    protected $fillable = [
        'brand', 'model', 'model_aliases', 'part_name', 'part_aliases', 'part_number', 'fuel',
        'serial_from', 'serial_to', 'status', 'superseded_by', 'source_type', 'source_ref', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['model_aliases' => 'array', 'part_aliases' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A part the business lists for its tech assistant - name, number, which models it fits. '
            .'Reference material holding nothing about a person; kept while the business keeps it.'
        );
    }
}
