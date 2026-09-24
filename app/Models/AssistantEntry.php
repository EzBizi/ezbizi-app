<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\SummarizesBulkAttributes;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One answer the staff assistant gives - on WhatsApp and inside the app.
 *
 * ── StatesRetention, and deliberately NOT HoldsPersonalData ──────
 *
 * It is the business's own reference material: what a code requires, which
 * lender to offer, where a branch is. Nothing about a person. Kept while the
 * business keeps it; deleting the entry, or the organization, takes it.
 *
 * ── Audience is a property of the ANSWER ─────────────────────────
 *
 * `staff` answers carry things a customer must not read - what the company pays
 * each lender, a dealer code. Deciding who is staff is AssistantBrain's job;
 * this only records which answers need it.
 */
class AssistantEntry extends Model implements StatesRetention, SummarizesBulkAttributes
{
    use BelongsToTenant;
    use HasUuids;

    public const AUDIENCE_PUBLIC = 'public';

    public const AUDIENCE_STAFF = 'staff';

    public const AUDIENCES = [self::AUDIENCE_PUBLIC, self::AUDIENCE_STAFF];

    /** WhatsApp's own limits, which the columns also hold. */
    public const MAX_SECTION = 24;

    public const MAX_TITLE = 24;

    public const MAX_DESCRIPTION = 72;

    public const MAX_BODY = 4096;

    protected $table = 'assistant_entries';

    protected $fillable = [
        'company_id', 'franchise_id',
        'section', 'title', 'description', 'body', 'audience', 'position',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * `body` is a whole answer: the audit row records that it changed, never
     * the text - see SummarizesBulkAttributes.
     */
    public function bulkAuditAttributes(): array
    {
        return ['body'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'An answer the business wrote for its staff assistant - what a code requires, '
            .'which lender to offer. The business\'s own reference material, holding nothing '
            .'about a person. Kept while the business keeps it; deleting the entry or the '
            .'organization removes it.'
        );
    }
}
