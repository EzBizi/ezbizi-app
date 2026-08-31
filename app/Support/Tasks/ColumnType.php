<?php

namespace App\Support\Tasks;

use App\Support\Enums\HasValues;

enum ColumnType: string
{
    use HasValues;

    case Text = 'text';
    case Number = 'number';
    case Checkbox = 'checkbox';
    case Date = 'date';
    case People = 'people';
    case Label = 'label';
    case Link = 'link';
    case File = 'file';
}
