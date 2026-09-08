<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A live chat on a customer's website, joined to the Lead that holds it.
 *
 * The messages live in `lead_conversations`; this is the visitor's end of the
 * conversation — the token their browser presents to read its own thread, and
 * nothing else. See the migration for why that cannot live on `leads`.
 */
#[Fillable([
    'site_id', 'lead_id', 'visitor_name', 'page_url',
])]
class WebChat extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'web_chats';

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'The visitor half of a website chat — a hashed token and the page it '
            .'started on. It has no meaning without the lead it belongs to and is '
            .'deleted with it, so whatever the enquiry is kept for, this is kept for '
            .'and no longer. The conversation itself is on the lead, under the Leads '
            .'module\'s own policy.'
        );
    }

    /**
     * A fresh visitor token: the plain string to hand back, and the hash to keep.
     *
     * Returned as a pair so the caller cannot accidentally store the plaintext —
     * there is no path here that writes it anywhere.
     *
     * @return array{0: string, 1: string}
     */
    public static function mintToken(): array
    {
        $plain = 'wc_'.Str::random(48);

        return [$plain, hash('sha256', $plain)];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WebSite::class, 'site_id');
    }
}
