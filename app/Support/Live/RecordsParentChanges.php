<?php

namespace App\Support\Live;

use Illuminate\Database\Eloquent\Model;

/**
 * A row that is part of another record on screen (a vehicle's insurance, a
 * person's time off) puts its PARENT on the change feed: the screen lists
 * the parent, so the parent is what changed. The model names it with
 * `protected static array $changeParent = [type, ParentClass::class, 'fk'];`.
 */
trait RecordsParentChanges
{
    public static function bootRecordsParentChanges(): void
    {
        $feed = function (Model $m): void {
            [$type, $class, $key] = static::$changeParent;
            $id = $m->getAttribute($key);
            if ($id === null) {
                return;
            }
            $parent = $class::withoutGlobalScopes()->find($id)
                ?? (new $class)->forceFill(['id' => $id, 'organization_id' => $m->getAttribute('organization_id')]);
            ChangeFeed::record($parent, $type);
        };
        static::saved(function (Model $m) use ($feed) {
            if ($m->wasRecentlyCreated || $m->wasChanged()) {
                $feed($m);
            }
        });
        static::deleted($feed);
    }
}
