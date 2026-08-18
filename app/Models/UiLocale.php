<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One language the app can speak (§8).
 *
 * Platform catalogue, not tenant data: English and Spanish ship as rows so a
 * third language is an insert, not a deploy of compiled files. Holds no person.
 */
class UiLocale extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The languages the app can speak. One row per locale, edited in place; adding a '
            .'language is an insert, not a log, and there is no age at which English stops '
            .'being current.'
        );
    }

    protected $table = 'ui_locales';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name'];

    public function strings(): HasMany
    {
        return $this->hasMany(UiString::class, 'locale', 'code');
    }
}
