<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business fact, for one site, optionally for one page.
 *
 * `path` empty is the site's answer; any other path is that page's override.
 * The plugin is handed both maps and picks per request, because only the plugin
 * knows which page is rendering.
 */
#[Fillable([
    'site_id', 'path', 'key', 'value',
])]
class WebValue extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A value saved is its site's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['web_sites', WebSite::class, 'site_id'];

    use HasUuids;

    protected $table = 'web_values';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'What the company publishes on its own website — its name, phone, address, '
            .'licence, hours. Public by definition and kept while the site is '
            .'registered. Deleting the site takes them.'
        );
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WebSite::class, 'site_id');
    }
}
