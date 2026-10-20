<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One week's or month's reports, waiting for (or given) the bookkeeper's approval. */
class WhatsAppReportBatch extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'whatsapp_report_batches';

    protected $fillable = ['organization_id', 'period', 'starts_on', 'ends_on', 'approved_at', 'approved_by', 'held_at', 'held_by', 'revision', 'approved_revision', 'approved_sha256'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'approved_at' => 'datetime', 'held_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(
            months: 24,
            column: 'created_at',
            because: 'Which weekly and monthly reports were approved, by whom and when. Kept two '
                .'years, as long as the messages they went out in.',
        );
    }
}
