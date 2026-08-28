<?php

namespace App\Support\Tasks;

enum TaskPresentation: string
{
    case Table = 'table';
    case Kanban = 'kanban';
    case Calendar = 'calendar';
    case Timeline = 'timeline';
    case Workload = 'workload';
}
