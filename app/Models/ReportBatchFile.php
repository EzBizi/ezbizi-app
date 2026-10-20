<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** One PDF of a report batch's revision, frozen at preview (WhatsAppPeriodReports). */
class ReportBatchFile extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'batch_id', 'revision', 'franchise_id', 'title', 'filename', 'path', 'sha256'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'created_at',
            'A financial report as it was approved and sent: kept seven years, as the books it reports on are.'
        );
    }

    public function bytes(): string
    {
        return (string) Storage::disk('local')->get($this->path);
    }

    protected static function booted(): void
    {
        // The row and its file go together.
        static::deleting(fn (self $file) => Storage::disk('local')->delete($file->path));
    }
}
