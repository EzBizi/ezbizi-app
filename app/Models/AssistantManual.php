<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A manufacturer's manual (PDF, private storage) and its text, page by page, for the Tech Assistant. */
class AssistantManual extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'assistant_manuals';

    protected $fillable = ['brand', 'models', 'title', 'revision', 'path', 'page_count', 'pages', 'created_by'];

    protected $hidden = ['pages'];

    protected function casts(): array
    {
        return ['pages' => 'array', 'page_count' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A manufacturer manual uploaded for the tech assistant. Reference material holding nothing '
            .'about a person; kept until the business deletes it.'
        );
    }
}
