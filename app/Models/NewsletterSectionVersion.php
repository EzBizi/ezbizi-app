<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;

/** One published version of a saved section; never changed once written (P1b). */
class NewsletterSectionVersion extends Model implements StatesRetention
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'newsletter_section_id', 'version', 'blocks', 'updated_by'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'version' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A published version of a saved newsletter section. Kept with the section so a newsletter '
            .'linked to an older version still shows what it was built with.'
        );
    }
}
