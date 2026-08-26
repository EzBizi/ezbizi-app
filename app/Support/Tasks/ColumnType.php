<?php

namespace App\Support\Tasks;

enum ColumnType: string
{
    case Text = 'text';
    case Number = 'number';
    case Checkbox = 'checkbox';
    case Date = 'date';
    case People = 'people';
    case Label = 'label';
    case Link = 'link';
    case File = 'file';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
