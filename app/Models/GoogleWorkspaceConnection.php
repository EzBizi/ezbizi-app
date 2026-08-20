<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One Google Workspace domain bound to one place in the tenant
 * (the organization, or a company).
 *
 * The SERVICE ACCOUNT that impersonates mailboxes is installation config, not
 * this row. This row is the proof that a Workspace administrator signed in as
 * somebody on this domain, and the admin address Directory listing impersonates.
 *
 * Domain is unique across the platform: tenant B must not be able to claim
 * a-1chimney.com after A-1 has granted EzBizi domain-wide delegation.
 *
 * ── Why NOT HoldsPersonalData ────────────────────────────────────
 *
 * `admin_email` is the Workspace administrator role used to list users — often
 * it@ or admin@ — the same kind of shop-sign address Mailbox argues is not a
 * person's mailbox. Who signed in, and when, is the audit log. Disconnecting
 * deletes the row.
 */
class GoogleWorkspaceConnection extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_PENDING = 'pending_delegation';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_BROKEN = 'broken';

    /**
     * Nothing is fillable. Domain uniqueness is a security binding: a request
     * body must never be able to claim another organization's Workspace.
     * {@see \App\Support\Email\GoogleWorkspace::bind} is the only writer.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The proof that a Workspace administrator signed in as somebody on this domain, '
            .'so EzBizi may impersonate listed shared inboxes with its service account. One '
            .'row per organization, held while the domain is connected and deleted when '
            .'somebody disconnects it. No mailbox password and no mail is stored. Who '
            .'connected it, and when, is the audit log, which does expire.'
        );
    }
}
