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
                try {
                    $slices[] = [
                        'label' => $this->sliceLabel($mailbox),
                        'total' => $this->readers->for($mailbox)->unreadCount($mailbox),
                    ];
                } catch (CannotReadMailbox|CannotReachHost) {
                    $sentences[] = sprintf(
                        '%s could not be asked for unread mail.',
                        $mailbox->address,
                    );
                }
            }

            $data = ['empty' => $slices === []];

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
