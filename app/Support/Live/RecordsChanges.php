<?php

namespace App\Support\Live;

use Illuminate\Database\Eloquent\Model;

/**
 * Puts a model's writes on the change feed (ChangeFeed). The model names its
 * feed type with `protected static string $changeType = 'jobs';`.
 */
trait RecordsChanges
{
    public static function bootRecordsChanges(): void
    {
        static::saved(function (Model $m) {
            if ($m->wasRecentlyCreated || $m->wasChanged()) {
                ChangeFeed::record($m, static::$changeType, method_exists($m, 'trashed') && $m->trashed());
            }
        });
        static::deleted(fn (Model $m) => ChangeFeed::record($m, static::$changeType, true));
        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $m) => ChangeFeed::record($m, static::$changeType));
        }
    }
}
