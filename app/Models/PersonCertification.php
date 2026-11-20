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

/**
 * A certification someone holds — a licence, a trade card — with who issued it
 * and when it runs out (Jose, 2026-11-20: Leroy's CSIA card). Its expiry feeds
 * the HR dashboard's expiring-certifications tile beside the contract ones.
 */
class PersonCertification extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'user_id', 'name', 'issuer', 'number', 'issued_on', 'expires_on', 'note',
    ];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Certifications and licences an employee holds, with the number and expiry, so HR '
            .'knows they are qualified and is warned before one runs out.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept while the account exists, like the rest of the employment record.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'The employee who holds the certification.',
                Erasure::drop('A certification is one person\'s; erasing them removes it.')),
            new PersonalDataEntry('number, note', PersonalDataEntry::CONTENT,
                'The certificate number and anything HR noted about it.',
                Erasure::drop('The number belongs to the certificate of that one person, so it goes with them.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
