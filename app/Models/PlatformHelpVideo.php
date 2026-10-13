<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One tab's how-to video (UI queue 81). Platform-wide: no organization, kept
 * by EzBizi staff in Staff › Help videos.
 */
class PlatformHelpVideo extends Model
{
    use HasUuids;

    protected $fillable = [
        'module_key', 'url', 'title', 'duration_seconds', 'chapters', 'captions_url', 'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'chapters' => 'array',
            'duration_seconds' => 'integer',
        ];
    }

    /** What a client needs to draw the button and play it. */
    public function present(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'duration_seconds' => $this->duration_seconds,
            'chapters' => $this->chapters ?? [],
            'captions_url' => $this->captions_url,
        ];
    }
}
