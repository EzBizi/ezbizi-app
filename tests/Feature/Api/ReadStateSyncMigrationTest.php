<?php

namespace Tests\Feature\Api;

use App\Models\Mailbox;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The default-on migration, run as the FILE against production's state.
 *
 * Every production mailbox held `mark_read_at_source = false` when this was
 * written. A fresh test database already has the new default, so without
 * putting a row back to false first, `up()` would update nothing and pass.
 */
class ReadStateSyncMigrationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const FILE = 'database/migrations/2026_10_06_130000_sync_read_state_with_the_mail_service_by_default.php';

    public function test_every_existing_mailbox_is_turned_on(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $id = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => 'info@a-1chimney.com',
            'display_name'    => 'Info',
            'provider'        => 'gmail_workspace',
        ])->getKey());

        // Production's state, exactly.
        DB::table('mailboxes')->where('id', $id)->update(['mark_read_at_source' => false]);
        $this->assertFalse((bool) DB::table('mailboxes')->where('id', $id)->value('mark_read_at_source'));

        /** @var Migration $migration */
        $migration = require base_path(self::FILE);
        $migration->up();

        $this->assertTrue((bool) DB::table('mailboxes')->where('id', $id)->value('mark_read_at_source'));
    }

    public function test_a_new_mailbox_is_on_without_anybody_choosing_it(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $id = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => 'new@a-1chimney.com',
            'display_name'    => 'New',
            'provider'        => 'gmail_workspace',
        ])->getKey());

        $this->assertTrue((bool) DB::table('mailboxes')->where('id', $id)->value('mark_read_at_source'));
    }
}
