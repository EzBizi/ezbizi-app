<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UiString extends Model
{
    protected $fillable = ['locale', 'key', 'text'];

    public function uiLocale(): BelongsTo
    {
        return $this->belongsTo(UiLocale::class, 'locale', 'code');
    }
}
