<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A short number that reaches one person on one phone account. */
class PhoneExtension extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'connection_id', 'user_id', 'number_id', 'extension',
        'ring_group_id', 'flow_id',
    ];

    /**
     * What an extension may look like: a leading zero, then one to seven more.
     *
     * ── Why the zero is load-bearing and not a house style ──────────
     *
     * Jose, 2026-09-22: *"all extensions should also be forced to begin with 0
     * as in 001 002"*.
     *
     * It is what makes an extension tellable apart from a menu key while a
     * caller is typing. A menu offers 1 to 9; if extensions could start with
     * those digits, a caller pressing 1 could be choosing option one or
     * beginning extension 103, and nothing in the keypress says which. A
     * leading zero answers that on the first digit.
     *
     * Two to eight characters total, unchanged — `0` alone is not an
     * extension, and eight is the column.
     */
    public const PATTERN = '/^0\d{1,7}$/';

    /** Dialling these digits rings one person. */
    public const TARGET_USER = 'user';

    /** Dialling these digits rings a team. */
    public const TARGET_RING_GROUP = 'ring_group';

    /** Dialling these digits plays a menu. */
    public const TARGET_MENU = 'menu';

    /**
     * What these digits reach, derived rather than stored.
     *
     * Exactly one of `user_id`, `ring_group_id` and `flow_id` is set, so the
     * kind is readable from the row and a fourth column holding the word would
     * be one more thing to keep in step with the other three. See the
     * migration for why this deliberately differs from
     * `phone_numbers.answer_with`, where the discriminator carries something
     * no column can.
     *
     * Null is a real answer and the Extensions screen shows it: an extension
     * whose team or menu was deleted keeps its digits — somebody has told
     * customers to dial 300 — and points at nothing until it is repointed.
     */
    public function targetKind(): ?string
    {
        return match (true) {
            $this->user_id !== null => self::TARGET_USER,
            $this->ring_group_id !== null => self::TARGET_RING_GROUP,
            $this->flow_id !== null => self::TARGET_MENU,
            default => null,
        };
    }

    public function ringGroup(): BelongsTo
    {
        return $this->belongsTo(PhoneRingGroup::class, 'ring_group_id');
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(PhoneFlow::class, 'flow_id');
    }

    /**
     * Goes with the phone account, and with the person.
     *
     * Both foreign keys cascade, so an extension cannot outlive either end of
     * what it connects. There is nothing to sweep on a timer: an extension is
     * an address, and an address that expires while the person is still here
     * would silently stop a caller reaching them.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'The short number callers can dial to reach one person directly — '
            .'the digits and who they ring. Deleted with the phone account, and '
            .'with the person it belongs to.'
        );
    }

    /**
     * The line this person's outbound calls go out from.
     *
     * Null is not a gap: it means they call out from whichever line the Call
     * tab has selected, which is how everybody behaved before this existed.
     */
    public function number(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'number_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }
}
