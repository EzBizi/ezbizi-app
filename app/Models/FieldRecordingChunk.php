<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One minute of a {@see FieldRecording}; the audio file is on the default disk at `path`. */
class FieldRecordingChunk extends Model implements StatesRetention
{
    use HasUuids;

    protected $fillable = ['recording_id', 'seq', 'started_at', 'duration_ms', 'bytes', 'sha256', 'path'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A minute of its recording, deleted with it; the sweep removes the audio file too.');
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(FieldRecording::class, 'recording_id');
    }
}
