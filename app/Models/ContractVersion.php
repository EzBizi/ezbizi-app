<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Contracts\FieldDefinition;
use App\Support\Contracts\FieldSet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An immutable published field list (EZBIZI-HR-PLAN.md, "The template is
 * versioned").
 *
 * Editing a contract publishes a new version and repoints the contract at it.
 * Nothing ever edits one of these, because every employment record remembers
 * which version produced it — and a version that can change turns a signed
 * record into a description of questions nobody was asked.
 *
 * `AutomationVersion` says the same thing about run logs. The consequence is
 * heavier here: a run log that misdescribes itself is a debugging nuisance, and
 * a contract that misdescribes itself is a document somebody signed.
 */
class ContractVersion extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;   // versions are written once

    protected $fillable = [
        'organization_id', 'contract_id', 'version', 'fields', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Contract versions are immutable. Changing a template means publishing a new '
                .'version — editing this one would rewrite the questions every record produced '
                .'by it claims to have been asked.'
            );
        });

        /*
         * And no deletes, for a reason that is not symmetry.
         *
         * `contract_signatures.contract_version_id` is `cascadeOnDelete`, so one
         * ordinary `$version->delete()` removed the published field list AND
         * every signature taken against it — the evidentiary record this module
         * is built around, gone, with no trace of the signature or of its
         * removal. It also left `contracts.current_version_id` pointing at
         * nothing, which the migration explicitly relies on never happening: it
         * omits a foreign key there on the argument that "nothing ever deletes a
         * version". That argument was a convention plus a guard against the
         * wrong verb. This is the guard it needed.
         */
        static::deleting(function () {
            throw new RuntimeException(
                'A published version cannot be deleted. Records made under it name it, and '
                .'the signatures taken against it hang off it — removing one would take '
                .'them with it. Publish a new version instead; the old one stays because '
                .'somebody agreed to it.'
            );
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fieldSet(): FieldSet
    {
        return FieldSet::fromStored($this->fields);
    }

    /**
     * The definition of one key, retired or not.
     *
     * This is what "a retired field stays readable on records that carry it"
     * means in code: a record was produced by a version, and that version can
     * always still say what its keys were called and how to read them. The
     * lookup deliberately does NOT skip retired fields — a record carrying an
     * answer to a field that has since been retired still needs its label.
     */
    public function field(string $key): ?FieldDefinition
    {
        return $this->fieldSet()->keyed()[$key] ?? null;
    }

    /**
     * What a NEW onboarding asks. Retired fields are gone from here and nowhere
     * else — that is the entire difference between retiring and deleting.
     *
     * @return FieldDefinition[]
     */
    public function liveFields(): array
    {
        return array_values(array_filter(
            $this->fieldSet()->fields,
            fn (FieldDefinition $f) => ! $f->retired,
        ));
    }
}
