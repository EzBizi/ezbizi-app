<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One thing a customer did with their review link (Customer Experience, 2026-10-15). */
#[Fillable(['customer_id', 'job_id', 'review_code', 'user_id', 'kind', 'occurred_at'])]
class ReviewLinkEvent extends Model implements StatesRetention
{
    use BelongsToTenant;

    public const KINDS = ['opened', 'happy', 'sad', 'submitted', 'google'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'That a customer opened their review link, which face they tapped, and whether they '
            .'went on to Google. Ids and a time only; deleted with the customer.'
        );
    }
}
