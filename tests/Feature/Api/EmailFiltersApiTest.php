<?php

namespace Tests\Feature\Api;

use App\Models\EmailFilter;
use App\Models\Mailbox;
use App\Support\Access\InboxAccess;
use App\Support\Email\MailboxReaders;
use App\Support\Email\MessagePage;
use App\Support\Email\MessageSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

// The reader fakes live beside the inbox tests.
require_once __DIR__.'/MailboxMessagesApiTest.php';

/** Email filters (Jose, 2026-10-28): personal for anyone with the Inbox, shared by permission at a place one reaches. */
class EmailFiltersApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeMailboxReader::$page = null;
        FakeMailboxReader::$pages = null;
        $this->app->bind(MailboxReaders::class, fn () => new FakeMailboxReaders);
        Cache::flush();
    }

    private function inbox(array $t): Mailbox
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'address' => 'office@a-1chimney.com', 'display_name' => 'Office', 'provider' => 'gmail_oauth',
            ]);
            $mailbox->connect('a-refresh-token');
            $mailbox->save();

            return $mailbox;
        });
    }

    private function mail(string $id, string $from): MessageSummary
    {
        return new MessageSummary(
            id: $id, threadId: 't-'.$id, subject: $id, fromName: 'Sender', fromAddress: $from,
            to: [], sentAt: Carbon::parse('2026-10-28 09:00:00'), snippet: '',
        );
    }

    public function test_a_shared_filter_files_mail_for_everyone_and_a_personal_one_only_for_its_owner(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inbox($t);
        $reader = $this->userWithRole($t, 'Reader', ['email.view', 'email.inbox.view']);

        $this->signedInAs($t['admin'])->postJson('/api/v1/email/filters', [
            'name' => 'Google LSA', 'from_contains' => 'localservices-noreply@google.com', 'action' => 'notifications',
            'scope_type' => 'company', 'scope_id' => $t['company']->id,
        ])->assertCreated()->assertJsonPath('data.shared', true);

        $this->signedInAs($t['admin'])->postJson('/api/v1/email/filters', [
            'name' => 'Mine', 'from_contains' => 'noise@example.com', 'action' => 'hide',
        ])->assertCreated()->assertJsonPath('data.shared', false);

        FakeMailboxReader::$page = new MessagePage([
            $this->mail('lsa', 'LocalServices-NoReply@google.com'),
            $this->mail('noise', 'noise@example.com'),
            $this->mail('bob', 'bob@example.com'),
        ]);

        $admin = collect($this->signedInAs($t['admin'])->getJson('/api/v1/email/inbox')->assertOk()->json('data'))->pluck('category', 'subject');
        $this->assertSame('notifications', $admin['lsa']);
        $this->assertSame('primary', $admin['bob']);
        $this->assertFalse($admin->has('noise'), 'Hide did not hide.');

        // The reader gets the shared filter, not the admin's own.
        $this->grantPerson($t, $reader);
        $theirs = collect($this->signedInAs($reader)->getJson('/api/v1/email/inbox')->assertOk()->json('data'))->pluck('category', 'subject');
        $this->assertSame('notifications', $theirs['lsa']);
        $this->assertSame('primary', $theirs['noise']);
        $this->assertCount(1, $this->signedInAs($reader)->getJson('/api/v1/email/filters')->json('data.filters'));
    }

    public function test_sharing_needs_the_permission_and_reach_but_a_personal_filter_does_not(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $reader = $this->userWithRole($t, 'Reader', ['email.view', 'email.inbox.view']);
        $body = ['name' => 'X', 'from_contains' => 'x@example.com', 'action' => 'notifications'];

        $this->signedInAs($reader)->postJson('/api/v1/email/filters', $body + ['scope_type' => 'company', 'scope_id' => $t['company']->id])
            ->assertForbidden();
        $this->signedInAs($reader)->postJson('/api/v1/email/filters', $body)->assertCreated();
        $this->signedInAs($reader)->getJson('/api/v1/email/filters')->assertJsonPath('data.may_share', false);

        $sharer = $this->userWithRole($t, 'Sharer', ['email.view', 'email.inbox.view', 'email.filters.share'], onlyAt: $t['franchise']);
        $this->signedInAs($sharer)->postJson('/api/v1/email/filters', $body + ['scope_type' => 'organization', 'scope_id' => $t['org']->id])
            ->assertForbidden();
        $this->signedInAs($sharer)->postJson('/api/v1/email/filters', $body + ['scope_type' => 'franchise', 'scope_id' => $t['franchise']->id])
            ->assertCreated();

        // Somebody else's personal filter cannot be deleted; a shared one needs the permission.
        $mine = $this->inTenant($t['org'], fn () => EmailFilter::query()->whereNotNull('user_id')->firstOrFail());
        $this->signedInAs($sharer)->deleteJson("/api/v1/email/filters/{$mine->id}")->assertNotFound();
        $this->signedInAs($reader)->deleteJson("/api/v1/email/filters/{$mine->id}")->assertOk();
    }

    private function grantPerson(array $t, $user): void
    {
        $this->inTenant($t['org'], function () use ($user) {
            foreach (Mailbox::all() as $mailbox) {
                app(InboxAccess::class)->givePerson($mailbox, $user);
            }
        });
    }
}
