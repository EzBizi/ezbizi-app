<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One phone texted by a promotion; the reply opens a lead on `lead_id`. */
#[Fillable(['organization_id', 'promotion_id', 'franchise_id', 'customer_id', 'job_id', 'lead_id', 'e164', 'contact_name', 'twilio_sid', 'body', 'error', 'sent_at'])]
class PromotionText extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A text sent, failed or answered is its promotion's change on the live feed (RecordsParentChanges): the counts move. */
    protected static array $changeParent = ['membership_promotions', Promotion::class, 'promotion_id'];

    use HasUuids;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(24, 'created_at',
            'Which phones a promotion texted: kept two years, so a reply finds its promotion and nobody is texted twice by one.');
    }

    public static function personalDataPurpose(): string
    {
        return 'One row per phone a promotion texted, so the customer\'s reply opens a lead with the text it answers.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        $drop = Erasure::drop('Dropping the row only loses the record that one promotion texted this person.');

        return [
            new PersonalDataEntry('e164', PersonalDataEntry::CONTACT, 'The number texted, and matched against a reply.', $drop),
            new PersonalDataEntry('contact_name', PersonalDataEntry::IDENTITY, 'Who was texted, for the lead a reply opens.', $drop),
            new PersonalDataEntry('body', PersonalDataEntry::CONTENT, 'The text as sent, with the customer\'s first name in it.', $drop),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof Customer ? static::query()->where('customer_id', $subject->getKey()) : null;
    }
}
