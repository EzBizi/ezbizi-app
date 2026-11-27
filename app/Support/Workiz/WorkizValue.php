<?php

namespace App\Support\Workiz;

/** How a Workiz payload's loose values become column values, where the attribute mappers agree. */
final class WorkizValue
{
    /**
     * Workiz's absent value is the empty string, not null — measured across 400
     * captured payloads — so `?? null` never fires and this is where the row's
     * NULLs actually come from. An array is null rather than the word "Array".
     */
    public static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * A wall-clock string, kept as one.
     *
     * No zone is available — `Timezone` is absent from every real payload — so
     * this refuses to invent one. A1 Tools' schema stores absent dates as
     * '0000-00-00', which MySQL will not accept into a DATETIME column.
     */
    public static function moment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return $value;
    }
}
