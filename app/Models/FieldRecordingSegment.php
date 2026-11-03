<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One stretch of speech in a {@see FieldRecording}, at clock time. Silence has none. */
class FieldRecordingSegment extends Model implements StatesRetention
{
    use HasUuids;

    // Milliseconds matter: a line maps back to the moment it was said.
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['recording_id', 'started_at', 'ended_at', 'text'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('The words of its recording, deleted with it (ninety days unless kept).');
    }
}
