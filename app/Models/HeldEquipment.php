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
 * A tool or piece of equipment somebody holds (Jose, 2026-09-29: "Tools &
 * equipment held").
 *
 * Custody, not stock: Inventory counts what the company owns, this says who has
 * it. A returned item keeps its row with `returned_on` set, so the list is also
 * the record of what was handed back.
 */
class HeldEquipment extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'held_equipment';

    public const CATEGORIES = ['tool', 'equipment'];

    protected $fillable = [
        'organization_id', 'user_id', 'name', 'category', 'quantity',
        'identifier', 'issued_on', 'returned_on', 'note', 'import_ref',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'integer',
            'issued_on'   => 'date',
            'returned_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Which company tools and equipment an employee holds, and when they were '
            .'issued and returned.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept while the account exists: it is the record of company property in somebody\'s '
            .'hands, and of what they gave back.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'The employee who holds, or held, the item.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at its '
                    .'one home. The item is company property and the row is its custody '
                    .'record, which stays true of an anonymised holder.')),
            new PersonalDataEntry('issued_on, returned_on, note', PersonalDataEntry::ACTIVITY,
                'When it was issued and returned, and any note about it.',
                Erasure::keep('Dates and a note about company property, not about the person.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
