<?php

namespace App\Models;

use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something an app reported going wrong on itself: a Dart error, a native
 * crash, a freeze, or a failed server call. See the create_client_issues
 * migration for why it is platform-wide and folded by fingerprint.
 *
 * Privacy line: `message` and `detail` are scrubbed on the way in
 * (ClientIssueScrubber) — no tokens, addresses, phone numbers, query strings
 * or request bodies are kept, only what code went wrong where.
 */
class ClientIssue extends Model implements HoldsPersonalData
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const KINDS = ['dart_error', 'native_crash', 'freeze', 'api_failure'];

    protected $fillable = [
        'organization_id', 'user_id', 'kind', 'fingerprint', 'message', 'detail',
        'platform', 'app_version', 'module', 'count', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function personalDataPurpose(): string
    {
        return 'Errors, crashes and freezes the EzBizi apps report about themselves, so they '
            .'can be found and fixed. Which person and organization saw it, nothing they typed.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'Long enough to see whether a fix worked across a few releases; after that an '
            .'error report describes a build nobody runs.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY,
                'Whose app reported the problem, so a support call can be matched to it.',
                Erasure::drop('The row is a record of that person\'s app failing; it goes.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
