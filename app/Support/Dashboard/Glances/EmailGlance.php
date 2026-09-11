<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Mailbox;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Email\CannotReachHost;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\MailboxAccess;
use App\Support\Email\MailboxReaders;
use App\Support\Tenancy\TenantContext;

/**
 * Email box: unread per readable inbox in the current view (snapshot).
 *
 * Unread is the provider's mailbox-level figure — never a stored message list.
 * A mailbox that cannot be asked is named in a sentence; the others still draw.
 */
final class EmailGlance extends DescribedGlance
{
    protected const KEY = 'email';
    protected const LABEL = 'Email';
    protected const PERMISSION = 'email.view';
    protected const PRESENTATIONS = ['bar', 'pie', 'text'];
    protected const DEFAULT_PRESENTATION = 'bar';
    protected const SNAPSHOT = true;

    public function __construct(
        private readonly MailboxAccess $access,
        private readonly MailboxReaders $readers,
    ) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $mailboxes = $this->access->readable($user)
                ->filter(fn (Mailbox $mailbox) => $this->access->inView($user, $mailbox))
                ->values();

            if ($mailboxes->isEmpty()) {
                return $this->payload([
                    'empty' => true,
                    'sentence' => 'No inbox is assigned to your role.',
                ]);
            }

            $slices = [];
            $sentences = [];

            foreach ($mailboxes as $mailbox) {
                // A broken mailbox is not asked. `WatchMailboxes` already skips
                // it on purpose, so nothing is arriving and nothing will until
                // somebody reconnects it -- asking anyway would spend the home
                // screen's load on a connection we know has failed, and would
                // then describe a permanent state in the words of a temporary
                // one. Two production mailboxes sat broken for three weeks
                // saying "could not be asked", which reads like a bad minute.
                if ($mailbox->status === Mailbox::STATUS_BROKEN) {
                    $sentences[] = sprintf(
                        '%s is disconnected. No mail is arriving from it until it is reconnected in Email, Settings.',
                        $mailbox->address,
                    );

                    continue;
                }

                try {
                    $slices[] = [
                        'label' => $this->sliceLabel($mailbox),
                        'total' => $this->readers->for($mailbox)->unreadCount($mailbox),
                    ];
                } catch (CannotReadMailbox|CannotReachHost) {
                    $sentences[] = sprintf(
                        '%s could not be asked for unread mail just now.',
                        $mailbox->address,
                    );
                }
            }

            // Never `empty: true` here, and the reason is no longer the one
            // this comment used to give.
            //
            // It was: the dashboard rendered ONE sentence for an empty glance
            // and silently dropped the rest, so every mailbox broken meant no
            // slices, several things to say, and only the first shown. That is
            // what hid a second dead inbox for three weeks. The Dashboard
            // session fixed the renderer itself in `e37502f4`, so the dropping
            // is gone and this line is no longer load-bearing against it.
            //
            // It stays `false` on its own merits: a box with something to say
            // is not empty, whatever the renderer does with it. Every mailbox
            // above contributes either a slice or a sentence, so there is
            // always something to draw.
            $data = ['empty' => false];

            if ($slices !== []) {
                $data['slices'] = $slices;
            }

            if ($sentences !== []) {
                $data['sentences'] = $sentences;
            }

            return $this->payload($data);
        });
    }

    private function sliceLabel(Mailbox $mailbox): string
    {
        $name = trim((string) $mailbox->display_name);

        return $name !== '' ? $name : $mailbox->address;
    }

    /** @param  array<string, mixed>  $data */
    private function payload(array $data): GlancePayload
    {
        return new GlancePayload(
            key: $this->key(),
            label: $this->label(),
            presentation: $this->defaultPresentation(),
            allowedPresentations: $this->allowedPresentations(),
            snapshot: $this->snapshot(),
            comingSoon: false,
            options: [],
            data: $data,
        );
    }
}
