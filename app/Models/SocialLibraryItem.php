<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Social\Publishing\PostPayload;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A picture or video in Social Media's standard library, picked in Compose. */
class SocialLibraryItem extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'scope_type', 'scope_id', 'name', 'disk', 'path', 'size', 'created_by',
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A picture or video the organization keeps to post again: the file and the name '
            .'somebody gave it. It is the business\'s own marketing material, kept until '
            .'somebody removes it from the library; a post made from it keeps its own copy.'
        );
    }

    public function kind(): string
    {
        return (new PostPayload(filePath: $this->path))->kind()->value;
    }

    public function mime(): string
    {
        return PostPayload::mimeForExtension(pathinfo($this->path, PATHINFO_EXTENSION));
    }
}
