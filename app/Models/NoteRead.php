<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** When one person last read a job's, lead's or task's notes (monday port, 2026-11-09). */
class NoteRead extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['organization_id', 'user_id', 'subject_type', 'subject_id', 'last_read_at'];

    protected $casts = ['last_read_at' => 'datetime'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A read mark goes with the person it belongs to.');
    }
}
