<?php

namespace App\Support\Web;

use App\Models\WebValue;
use Illuminate\Support\Collection;

/**
 * The values half of every Web Management response, in ONE shape.
 *
 * ── The bug this class exists to make impossible ──
 *
 * `pluck()->all()` returns a PHP array. A populated one has string keys and
 * `json_encode` writes an object; an EMPTY one is `[]` and json_encode writes
 * an ARRAY. So the same endpoint answered `{"values":{"phone":"555"}}` for a
 * site with details and `{"values":[]}` for one without — two different types
 * on one field, decided by whether anybody had filled the form in yet.
 *
 * The Flutter panel casts that field to a Map. On a site with no values the
 * cast threw, the exception escaped before `_loading` could be set false, and
 * the panel span forever. Which made it a FIRST-RUN DEADLOCK: you could not
 * open the panel until the site had values, and you could not add values
 * without opening the panel. Every newly registered site hit it; ezbizi.com,
 * the first real one, hit it immediately.
 *
 * Casting to object is what fixes it — `(object) []` encodes as `{}`. Doing it
 * here rather than in each controller is the point: three endpoints returned
 * this shape and a fourth was about to, and a fix applied three times is a bug
 * waiting for the fourth.
 */
final class ValuePayload
{
    /**
     * @param  Collection<int, WebValue>  $rows  every value row for one site
     * @return array{values: object, pages: object}
     */
    public static function forRows(Collection $rows): array
    {
        return [
            'values' => (object) $rows->where('path', '')->pluck('value', 'key')->all(),
            'pages' => (object) $rows->where('path', '!=', '')
                ->groupBy('path')
                ->map(fn (Collection $group) => (object) $group->pluck('value', 'key')->all())
                ->all(),
        ];
    }
}
