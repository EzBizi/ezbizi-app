<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A person on a company's "meet the team" page, drawn by [ezbizi_team].
 *
 * NOT an employee record, on purpose. Somebody appears on a public website
 * only because a person typed them into this list; nothing in HR puts them
 * here. That is the answer to "does hiring somebody publish their face".
 */
#[Fillable([
    'company_id', 'name', 'role', 'bio', 'photo_url', 'category', 'sort_order',
])]
class WebTeamMember extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'web_team_members';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The people a company chooses to show on its website. Public by intent and '
            .'kept while the company keeps them on the list; removing somebody from the '
            .'list deletes their row.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'So a company\'s website can show the people who do the work, as the company chose to present them.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name, role, bio, photo_url', PersonalDataEntry::IDENTITY,
                'A name, job title, short biography and photo, published on the company\'s website.',
                Erasure::drop('The row exists only to publish this person. With them taken out '
                    .'there is nothing left to show, so it is deleted rather than blanked.')),
        ];
    }

    /**
     * Not linked to any user or customer: a row is found by the company
     * looking at its own list, which the Widgets tab shows in full.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
