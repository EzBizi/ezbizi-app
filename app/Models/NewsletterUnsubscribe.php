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

/**
 * Somebody who asked to stop, and the place they asked to stop from.
 *
 * ── Why this outlives the audience ──────────────────────────────
 *
 * Deleting a list and rebuilding it must not resurrect the people who left. So
 * an unsubscribe is recorded against the SCOPE, not against the audience, and
 * every send in that scope reads it.
 *
 * ── Why it is scoped rather than global ─────────────────────────
 *
 * Leaving A-1 Chimney's mailing list must not remove somebody from Premier
 * Garage's. They are different businesses that happen to share a customer, and
 * one tenant must never be able to learn who unsubscribed from another.
 */
class NewsletterUnsubscribe extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'An email address that asked to stop receiving marketing mail from one company, '
            .'and when. Kept for as long as the company sends newsletters at all: forgetting '
            .'it is how somebody who opted out starts receiving mail again, so this row is '
            .'the record that protects them rather than data held about them for our benefit.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'One row per address that asked to stop receiving marketing mail from one '
            .'company. The whole row exists to protect the person it names: it is what a '
            .'send is checked against, so that somebody who opted out stays opted out when '
            .'a list is deleted and rebuilt.';
    }

    /**
     * The address, and why erasure does not take it.
     *
     * ── The one entry here that will be challenged ───────────────
     *
     * `Erasure::keep` on an email address looks wrong at a glance, so the
     * reasoning has to survive next to it. Erasing this row does not protect
     * the person — it forgets that they objected, and the next mailing reaches
     * them again. The address is held for no purpose except suppression, which
     * is the purpose they themselves asked for.
     *
     * That is why the row is scoped and why a tenant can never read it: it is
     * checked against, never listed. Somebody who wants it genuinely gone wants
     * the newsletter to stop existing for that company, and deleting the
     * organization takes it with the cascade.
     *
     * @return PersonalDataEntry[]
     */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry(
                'email',
                PersonalDataEntry::CONTACT,
                'Matching a mailing against the people who asked not to receive it. It is '
                .'compared and never displayed, and no screen in the product lists these '
                .'addresses back to a tenant.',
                Erasure::keep(
                    'Deleting it is how an opt-out is undone: the suppression list is the '
                    .'only record that the person objected, so erasing the address starts '
                    .'the mail again. It is kept to honour the request it represents, not '
                    .'for any use of ours, and it is never shown to anybody.'
                ),
            ),
        ];
    }

    /**
     * Every scope this person has left, within the organization asking.
     *
     * Matched on the ADDRESS rather than a foreign key, because that is all
     * this table holds — an unsubscribe is not tied to a customer record and
     * survives one being deleted, which is the point of it.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        $email = $subject->getAttribute('email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        return static::query()
            ->where('organization_id', $subject->getAttribute('organization_id'))
            ->where('email', $email);
    }

    protected $fillable = ['email', 'scope_type', 'scope_id', 'unsubscribed_at'];

    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime'];
    }
}
