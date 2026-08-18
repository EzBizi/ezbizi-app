<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UiLocale extends Model
{
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
