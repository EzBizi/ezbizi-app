<?php

namespace App\Support\Subscriptions;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether a lapsed organization may perform this write (plan §13).
 *
 * ── Why this is not middleware ───────────────────────────────────
 *
 * §13 says it in as many words: "Middleware on HTTP endpoints is not
 * sufficient. A deactivated organization must be unable to write via HTTP, the
 * public API, scheduled tasks, workflow actions, queued jobs, imports, API
 * tokens and WebSocket events. A workflow that keeps firing after expiry, or an
 * import that still runs, means the product works fine without paying."
 *
 * EnforceSubscriptionState covers the first of those and nothing else. The
 * workflow engine runs in a queue worker with no request at all; so does the
 * outbox sweep, the retention command, and anything imported.
 *
 * This is consulted from BelongsToTenant's write hooks instead — the one place
 * every tenant-owned write already converges, whatever started it. Adding a
 * check there is the difference between an edge that can be walked around and a
 * floor that cannot.
 *
 * ── What stays permitted, and why it must ────────────────────────
 *
 * A blanket lock takes away the actions that END the situation. §13 lists them;
 * they are implemented below rather than left as a table nobody enforces.
 */
class SubscriptionGuard
{
    /**
     * Models that stay writable regardless.
     *
     * Notifications, because telling somebody their trial has lapsed is how they
     * find out — and a system that goes silent at the moment it starts refusing
     * things is one nobody can diagnose.
     *
     * AuditEvent is absent because it deliberately does not use BelongsToTenant,
     * so it never reaches this at all: §13's "audit and security event writes
     * must keep recording regardless" is satisfied by construction rather than
     * by an exception here.
     */
    private const ALWAYS_WRITABLE = [
        Notification::class,

        // The engine's own bookkeeping — the outbox row and the run ledger.
        // These are not business data; they are the record of what the engine
        // did, including what it REFUSED to do. Blocking them would leave every
        // event permanently undelivered, so the outbox would grow without bound
        // and the retry sweep would pick the same rows up forever. The actions
        // themselves are still refused; WorkflowEngine::deliver() checks once up
        // front and stops the event with a reason.
        WorkflowEvent::class,
        WorkflowRun::class,
    ];

    /**
     * Fields on a User that stay writable.
     *
     * Password and MFA recovery (§13): otherwise expiry locks people out
     * permanently, and a customer who cannot get into their account cannot
     * update their billing either — which is the one action that fixes this.
     *
     * Preferences are here for a smaller reason: a dark-mode toggle that fails
     * with "your subscription has ended" is a confusing way to learn about a
     * billing problem, and nothing about it is a business write.
     */
    private const USER_FIELDS_ALWAYS_WRITABLE = [
        'password', 'totp_secret', 'pending_totp_secret', 'totp_confirmed_at',
        'last_totp_timestamp', 'recovery_codes', 'preferences',
        'remember_token', 'email_verified_at',
    ];

    /**
     * May this specific write proceed?
     *
     * Defaults to YES when the state cannot be determined. An organization that
     * has not been loaded is not evidence of non-payment, and refusing writes on
     * a failed lookup would turn a database hiccup into an outage for everybody.
     */
    public function permits(Model $model): bool
    {
        $organization = $this->organizationFor($model);

        if ($organization === null || ! $organization->isReadOnly()) {
            return true;
        }

        foreach (self::ALWAYS_WRITABLE as $class) {
            if ($model instanceof $class) {
                return true;
            }
        }

        if ($model instanceof User) {
            // Only if EVERY changed field is one of the permitted ones. A single
            // business field mixed into the same save is still a business write,
            // and letting it through because it travelled with a password change
            // is exactly the kind of gap that gets found later.
            $changed = array_keys($model->getDirty());

            return $changed !== [] && array_diff($changed, self::USER_FIELDS_ALWAYS_WRITABLE) === [];
        }

        return false;
    }

    /**
     * The message somebody actually sees.
     *
     * Says what they CAN do. "Read-only" on its own reads as data loss; the
     * useful information is that the data is intact and how to fix it.
     */
    public function refusal(Model $model): string
    {
        return sprintf(
            'This organization is read-only — its subscription has ended, so %s cannot be '
            .'saved. Your data is intact and still exportable, and updating billing will '
            .'restore writing immediately.',
            class_basename($model),
        );
    }

    private function organizationFor(Model $model): ?Organization
    {
        $id = $model->getAttribute('organization_id');

        if ($id === null) {
            return null;
        }

        // Cached per request: a bulk write would otherwise ask the same question
        // once per row, and the answer cannot change mid-request.
        return static::$cache[$id] ??= Organization::withoutGlobalScopes()->find($id);
    }

    /** @var array<string, Organization|null> */
    private static array $cache = [];

    /** Tests and long-lived workers change organizations underneath this. */
    public static function flush(): void
    {
        static::$cache = [];
    }
}
