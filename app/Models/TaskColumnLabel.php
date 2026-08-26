<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskColumnLabel extends Model implements StatesRetention
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['task_id', 'column_definition_id', 'column_option_id'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('The label relationship remains with current and archived task history; its text is inventoried on the option model.');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TaskColumnDefinition::class, 'column_definition_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(TaskColumnOption::class, 'column_option_id');
    }
}
