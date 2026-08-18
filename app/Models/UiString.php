<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One translated screen string in one locale (§8).
 *
 * Current wording, overwritten when the wording changes. Holds no person.
 */
class UiString extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'Each row is the current wording of one screen string in one locale, overwritten '
            .'when the wording changes rather than appended to. It goes with the language it '
            .'belongs to.'
        );
    }

    protected $fillable = ['locale', 'key', 'text'];

    public function uiLocale(): BelongsTo
    {
        return $this->belongsTo(UiLocale::class, 'locale', 'code');
    }
}
