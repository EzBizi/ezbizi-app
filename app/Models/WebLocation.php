<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A place the company trades from, as its websites list it.
 *
 * With no `site_id` it is one of the company's stores ([ezbizi_store_locator]).
 * With one, it is a branch that website answers for ([ezbizi_branches]) — the
 * list A1 Tools called a site's franchises. Not `Franchise`, which is how
 * EzBizi splits a company for staff and reporting; a website branch is only
 * what a page shows.
 */
#[Fillable([
    'company_id', 'site_id', 'name', 'phone', 'email', 'website_url', 'maps_url',
    'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
    'latitude', 'longitude', 'image_url', 'sort_order',
])]
class WebLocation extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'web_locations';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The addresses and phone numbers a company publishes for its own shops and branches. '
            .'Public by definition and kept until the company removes them.'
        );
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'sort_order' => 'integer',
        ];
    }
}
