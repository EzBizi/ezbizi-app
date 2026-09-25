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
 * One entry of a list a company's websites draw: an FAQ, a service, a
 * before-and-after pair or a hand-picked review. See the migration for why
 * one table holds four kinds.
 */
#[Fillable([
    'company_id', 'kind', 'title', 'body', 'subtitle', 'image_url', 'image2_url',
    'link_url', 'phone', 'icon', 'category', 'rating', 'dated', 'sort_order',
])]
class WebItem extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const KINDS = ['faq', 'service', 'before_after', 'review'];

    protected $table = 'web_items';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'What a company chooses to publish on its own website: its questions and answers, '
            .'services, project photos and the reviews it features. Kept until the company removes them.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'So a company can feature a customer\'s review on its own website, under the name the customer reviewed with.';
    }

    public static function personalDataHeld(): array
    {
        return [
            // Only a review row is about a person; an FAQ, a service or a
            // project photo is the company's own words and pictures.
            new PersonalDataEntry('title, body, image_url', PersonalDataEntry::CONTENT,
                'On a featured review: the reviewer\'s public name, photo and words, copied from where they published the review.',
                Erasure::drop('A featured review exists only to show that person\'s words; without them the row is empty.')),
            new PersonalDataEntry('phone', PersonalDataEntry::CONTACT,
                'The number a listed service shows: the business\'s own line.',
                Erasure::keep('A business line published by the company, not a person\'s number.')),
        ];
    }

    /** Not linked to a customer record: found by the company in its own list. */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'dated' => 'date:Y-m-d',
            'sort_order' => 'integer',
        ];
    }
}
