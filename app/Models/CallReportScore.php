<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AI's assessment of how a dispatcher handled one call, for coaching (Jose,
 * 2026-10-12, AI item 8). Keyed by call; who answered comes from the call log
 * in the Performance report, as the labels do.
 */
class CallReportScore extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'call_id', 'score', 'checks', 'strength', 'improve'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'score' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at',
            'An AI coaching note on how a call was handled, kept as long as the call\'s transcript is.'
        );
    }
}
