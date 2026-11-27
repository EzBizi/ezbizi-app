<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's newsletter brand (P1b, 2026-11-18): logo, colours, font stack,
 * default button and footer note, one per scope. "Apply brand" copies it onto
 * a newsletter (`newsletters.brand`), so a later edit here changes nothing
 * already written.
 */
class NewsletterBrand extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['scope_type', 'scope_id', 'settings', 'updated_by'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A company’s newsletter look: logo picture address, colours, font, default button and a '
            .'footer note. No personal data; kept while the company uses newsletters.'
        );
    }
}
