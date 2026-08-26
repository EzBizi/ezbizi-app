<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskImportSession extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'active_view_id', 'actor_user_id', 'token_hash', 'nonce', 'disk', 'path', 'bytes', 'sha256', 'normalized_mapping', 'structure_version', 'expires_at'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'normalized_mapping' => 'array', 'structure_version' => 'integer', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::elsewhere('the locked import-session expiry cleanup', 'Unconsumed staging metadata and bytes are removed after 30 minutes; consumed sessions are removed after commit cleanup.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Short-lived import staging metadata bound to one actor, board, active view, file identity, and normalized mapping.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('actor_user_id, disk, path, bytes, sha256, normalized_mapping', PersonalDataEntry::CONTENT, 'The importing person plus staged path and mapping metadata; source headings or filenames can contain personal details.', Erasure::drop('The entire short-lived staging session and its bytes are removed together by commit or expiry cleanup.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('actor_user_id', $subject->getKey()) : null;
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }

    public function activeView(): BelongsTo
    {
        return $this->belongsTo(TaskView::class, 'active_view_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
