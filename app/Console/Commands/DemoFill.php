<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\Attachment;
use App\Models\Chat;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleServiceVisit;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fill the demo tenant so its screens have something on them.
 *
 * ── Why this is not another `*:seed-demo` ────────────────────────
 *
 * The four existing seeders all open with the same guard:
 *
 *     if ($database === 'ezbizi' || ! str_contains($database, 'testing')) { refuse }
 *
 * and they are right to. That line was added after `db:wipe --env=testing
 * --force` fell through to `.env` on 2026-08-06 and named the live schema.
 *
 * But the demo tenant is ON the production database and always will be, because
 * the screenshot run signs into https://app.ezbizi.com. So that guard can never
 * be satisfied here, and the wrong answer is to relax it — the guard protects
 * against a command that can address the WHOLE database, which is what those
 * seeders are.
 *
 * This is a different risk class and is built as one. It cannot address the
 * whole database: it refuses to run against any organization but the demo one,
 * matched on BOTH its id and its name, so a renamed organization or a reused id
 * stops it rather than a typo reaching a customer. It only ever inserts. There
 * is no truncate, no delete, no update of an existing row anywhere below, so the
 * worst a bug here can do is add rows to a tenant that exists to hold invented
 * ones. Running it twice is a no-op rather than doubles, on any day — where a
 * row has a real natural key (an area's name, a zip, a weekday slot, a
 * vehicle's one open assignment) that key does it; where it has none, the guard
 * is whether the parent already has demo history, because the alternative keys
 * are dates derived from now() and those stop matching tomorrow.
 *
 * ── What it deliberately does not fill ──────────────────────────
 *
 * Phone, Web and Email are absent on purpose. A PhoneNumber row is the app's
 * handle on a real Twilio number, and WebSite and Mailbox are live connections;
 * inventing those gives a demo that dials, syncs or bills against something
 * nobody owns. Jose's call on 2026-09-04 was to drop the Communication screen
 * rather than park a number here.
 *
 * Contracts, Onboarding and Training are absent for a duller reason:
 * `contract_versions.fields` and `training_guides.screens` are json columns
 * whose shape is decided by their modules, and inventing a schema I have not
 * read produces rows that render wrong rather than rows that are missing. A
 * missing section is honest; a malformed one is a bug report. Whoever owns HR
 * should add those.
 *
 * Inspection is absent because the Inspection session owns it (docs/SESSIONS.md)
 * and is doing its own slice.
 *
 * See docs/demo-tenant-gaps.md for the measured counts this works from.
 */
class DemoFill extends Command
{
    protected $signature = 'demo:fill {--dry-run : Say what would be written and write nothing}';

    protected $description = 'Give the demo tenant enough data that its screens are not empty';

    /**
     * Three conditions, all of which must hold. Being exact about what each one
     * buys, because a comment that flatters the guard is worse than none.
     *
     * The ID IS THE LOOKUP, not a check — handle() finds by it, so this command
     * addresses one row of one table and can address no other. That is the whole
     * safety property. It also means a SECOND demo tenant, however honestly it
     * set is_demo, is not served here at all; it would need this constant
     * changed. That is a limitation, not an oversight, and it is the right way
     * round for something that writes to the production database.
     *
     * `is_demo` and the name are refusals stacked on top, for the case where
     * that one id stops meaning what it means today — recycled onto something
     * real, or the demo organization renamed into a customer. is_demo is the
     * column the schema provides for this exact question
     * (create_foundation_tables, set by ProvisionOrganization) and production
     * has it true on EzBizi Demo and false on A1-Group; the name catches a flag
     * somebody set on a real tenant to try something out.
     *
     * Measured, not copied: both ids were read off production directly on
     * 2026-09-04. That matters here in a way it would not in a purely
     * fail-closed gate — this one PROCEEDS on a match, so a wrong constant
     * could have pointed a write at the wrong tenant rather than merely
     * refusing. Re-measure before trusting it, per docs/demo-tenant-gaps.md.
     */
    private const DEMO_ID = '01a03e4f-f9a1-7012-9515-96bc9260d2f8';

    private const DEMO_NAME = 'EzBizi Demo';

    /** Every user this command creates, and the only ones it will ever pick. */
    private const STAFF_DOMAIN = 'demo.ezbizi.invalid';

    private string $organizationId = '';

    public function handle(TenantContext $tenant): int
    {
        $organization = Organization::query()->find(self::DEMO_ID);

        if ($organization === null
            || ! $organization->is_demo
            || $organization->name !== self::DEMO_NAME) {
            $this->error(sprintf(
                'Refusing to run. Expected the organization %s, named "%s", with is_demo set; found %s.',
                self::DEMO_ID,
                self::DEMO_NAME,
                $organization === null
                    ? 'nothing'
                    : sprintf('"%s" with is_demo %s', $organization->name, $organization->is_demo ? 'set' : 'UNSET'),
            ));

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $this->line(sprintf('%s %s', $dry ? 'Would fill' : 'Filling', $organization->name));

        $this->organizationId = $organization->id;

        $tenant->run($organization, function () use ($dry) {
            $this->staff($dry);
            $this->scheduling($dry);
            $this->fleet($dry);
            $this->timeClock($dry);
            $this->chat($dry);
        });

        return self::SUCCESS;
    }

    /**
     * The demo tenant had no staff — only the eight store review logins.
     *
     * Everything downstream needs people: a vehicle needs a holder, a shift
     * needs somebody clocked in, and the HR panel counts heads. Without this
     * step the only names available were the reviewers' own.
     *
     * `.invalid` is reserved by RFC 2606 and resolves nowhere, so these
     * addresses cannot receive mail however they are used. The password is 32
     * random bytes that are never printed and never stored anywhere else — these
     * accounts exist to be NAMED on a screen, not to be signed into, and nobody
     * including me can sign into them. If a demo login is ever wanted, it should
     * be made deliberately rather than by reusing one of these.
     */
    private function staff(bool $dry): void
    {
        $franchise = Franchise::query()->orderBy('created_at')->first();

        $people = [
            'Ada Vance' => 'ada.vance',
            'Marcus Iheme' => 'marcus.iheme',
            'Priya Raghunathan' => 'priya.raghunathan',
            'Tomas Bergqvist' => 'tomas.bergqvist',
            'Rosa Delgado' => 'rosa.delgado',
        ];

        foreach ($people as $name => $handle) {
            $email = $handle.'@'.self::STAFF_DOMAIN;

            if ($dry) {
                $exists = User::query()->where('email', $email)->exists();
                $this->line(sprintf('  staff: %s %s', $exists ? 'already there:' : 'would add', $name));

                continue;
            }

            $user = User::query()->firstOrNew(['email' => $email]);

            if ($user->exists) {
                continue;
            }

            $user->fill([
                'name' => $name,
                'is_active' => true,
                // Never printed, never reused. See the docblock.
                'password' => bin2hex(random_bytes(32)),
            ]);
            $user->organization_id = $this->organizationId;
            $user->save();

            // A user with no Assignment row is "unplaced", which is a real
            // state with real consequences and not a tidiness point. HR's
            // overview gives unplaced people their own row reading
            // "Not assigned to a place" — five of those on a store screenshot
            // reads as a broken install. Worse, TimeClock::visibleTo and
            // HrPeopleController::visibleTo only show unplaced people to
            // somebody standing at the ORGANIZATION, so a franchise-level
            // viewer would see the vans assigned to nobody and an empty clock.
            //
            // Placing them at the franchise that already owns the service
            // areas makes them ordinary staff, which is what a demo tenant is
            // supposed to look like.
            if ($franchise !== null) {
                Assignment::query()->firstOrCreate([
                    'user_id' => $user->id,
                ], [
                    'assigned_franchise_id' => $franchise->getKey(),
                ]);
            }

            $this->info(sprintf('  staff: added %s', $name));
        }
    }

    /**
     * Scheduling shows "Search a zip code or an area name to see its week" and
     * nothing else until an area exists, because there is nothing to search.
     */
    private function scheduling(bool $dry): void
    {
        $franchise = Franchise::query()->orderBy('created_at')->first();

        if ($franchise === null) {
            $this->warn('  scheduling: no franchise to own an area, skipped');

            return;
        }

        // Zips are the ones already used by the demo customers' cities, so a
        // search for an area finds work that exists rather than an empty week.
        $areas = [
            'Orlando — north' => ['32801', '32803', '32804', '32806'],
            'Miami — coastal' => ['33101', '33125', '33127'],
            'Fort Lauderdale' => ['33301', '33304', '33308'],
        ];

        foreach ($areas as $name => $zips) {
            if ($dry) {
                $this->line(sprintf('  scheduling: would add "%s" (%d zips, Mon-Fri)', $name, count($zips)));

                continue;
            }

            $area = ServiceArea::query()->firstOrCreate(
                ['name' => $name],
                ['franchise_id' => $franchise->id],
            );

            foreach ($zips as $zip) {
                ServiceAreaZip::query()->firstOrCreate([
                    'service_area_id' => $area->id,
                    'zip' => $zip,
                ], [
                    'franchise_id' => $franchise->id,
                ]);
            }

            // Monday to Friday. weekday is 0-6 (SchedulingAreasController:166
            // validates between:0,6), so 1..5 is the working week.
            foreach (range(1, 5) as $weekday) {
                ServiceAreaSlot::query()->firstOrCreate([
                    'service_area_id' => $area->id,
                    'weekday' => $weekday,
                    'start_time' => '08:00:00',
                ], [
                    'end_time' => '17:00:00',
                    'max_jobs' => 6,
                ]);
            }

            $this->info(sprintf('  scheduling: "%s" — %d zips, Mon-Fri 08:00-17:00', $name, count($zips)));
        }
    }

    /**
     * Five real vehicles all captioned "Nobody has the keys", because there is
     * not one row in vehicle_assignments.
     */
    private function fleet(bool $dry): void
    {
        $vehicles = Vehicle::query()->orderBy('number')->get();
        $drivers = $this->demoStaff();

        if ($vehicles->isEmpty() || $drivers->isEmpty()) {
            $this->warn('  fleet: no vehicles or no active users, skipped');

            return;
        }

        foreach ($vehicles as $i => $vehicle) {
            $driver = $drivers[$i % $drivers->count()];

            if ($dry) {
                $this->line(sprintf('  fleet: would give %s to %s', $vehicle->number, $driver->name));

                continue;
            }

            // `open` is what makes it the current holder rather than history, so
            // firstOrCreate keys on it: a second run finds the open row and adds
            // nothing.
            VehicleAssignment::query()->firstOrCreate([
                'vehicle_id' => $vehicle->id,
                'open' => 1,
            ], [
                'user_id' => $driver->id,
                'assigned_at' => Carbon::now()->subDays(30 + $i),
                'note' => 'Assigned for demonstration data.',
            ]);

            // NOT firstOrCreate on the date. `occurred_on` is derived from
            // now(), so the key it forms is different tomorrow: a second run on
            // a later day would match nothing and add a second visit, and a
            // third would add a third. The idempotency would have looked real
            // in any test that runs both passes in the same second, which is
            // every test that does not travel.
            //
            // There is no natural key here — a vehicle may legibly have two
            // services on one day — so the honest question is not "does THIS
            // visit exist" but "has this vehicle been given demo history yet".
            $hasHistory = VehicleServiceVisit::query()
                ->where('vehicle_id', $vehicle->id)
                ->exists();

            $hasHistory || VehicleServiceVisit::query()->create([
                'vehicle_id' => $vehicle->id,
                'occurred_on' => Carbon::now()->subMonths(2)->toDateString(),
                // VehicleServiceKind has no generic 'service' case — oil, tires,
                // brakes, repair, inspection, registration, other.
                'kind' => 'oil',
                'odometer_miles' => 42000 + ($i * 3100),
                'performed_by' => 'Ridgeline Fleet Services',
                'notes' => 'Oil and filters, with a brake check while it was up.',
                'next_service_on' => Carbon::now()->addMonths(4)->toDateString(),
            ]);

            $this->info(sprintf('  fleet: %s -> %s, one service visit', $vehicle->number, $driver->name));
        }
    }

    /**
     * The HR panel reads "0 people joined this month" and so on down the list.
     * Shifts are the one line here whose shape is plain columns rather than a
     * json schema owned by another module.
     */
    /**
     * The demo tenant's INVENTED staff, and nobody else.
     *
     * This is not a refinement, it is the difference between usable screenshots
     * and unusable ones. The demo organization holds exactly eight users and
     * every one of them is a store review login — apple@, google@, tiktok@,
     * meta@, x@, pinterest@, linkedin@, android@. The old selector took the
     * four oldest active users, so a dry run on 2026-09-04 proposed giving the
     * vans to "Apple Review", "Google Review" and "TikTok Review" and putting
     * them on the time clock. Those names would then have appeared in the App
     * Store screenshots, where an Apple reviewer would meet their own account
     * driving a truck.
     *
     * Matching on the demo email domain rather than excluding @ezbizi.com is
     * deliberate: an exclusion list is only correct until somebody adds a login
     * it does not know about, and it fails OPEN — the wrong name ships. This
     * fails closed. A user this command did not create is never chosen, so the
     * worst case is that a step skips and says so.
     */
    private function demoStaff(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where('email', 'like', '%@'.self::STAFF_DOMAIN)
            ->orderBy('created_at')
            ->get();
    }

    private function timeClock(bool $dry): void
    {
        $staff = $this->demoStaff()->take(4);

        if ($staff->isEmpty()) {
            $this->warn('  time clock: no active users, skipped');

            return;
        }

        foreach ($staff as $i => $person) {
            // Same trap as the service visits, same answer: clocked_in_at is
            // derived from now(), so keying on it makes a run on a later day
            // add a second pair of shifts rather than find the first. A person
            // has no natural key for "the demo shift" — two shifts on one day
            // is ordinary — so the question is whether this person has any
            // history at all.
            $hasShifts = TimeClockShift::query()
                ->where('user_id', $person->id)
                ->exists();

            // Two closed shifts each over the last week, and the first two
            // people still on the clock today, so the screen shows both states.
            foreach ([3, 2] as $daysAgo) {
                $in = Carbon::now()->subDays($daysAgo)->setTime(8, 0);

                if ($dry) {
                    $this->line(sprintf('  time clock: would add %s on %s', $person->name, $in->toDateString()));

                    continue;
                }

                if ($hasShifts) {
                    continue;
                }

                TimeClockShift::query()->create([
                    'user_id' => $person->id,
                    'clocked_in_at' => $in,
                    'clocked_out_at' => $in->copy()->setTime(16, 30),
                ]);
            }

            if ($i < 2 && ! $dry) {
                // open_slot is what keeps "one open shift per person" unique, so
                // an open row is keyed on it rather than on a time.
                TimeClockShift::query()->firstOrCreate([
                    'user_id' => $person->id,
                    'open_slot' => 1,
                ], [
                    'clocked_in_at' => Carbon::now()->setTime(8, 0),
                    'clocked_out_at' => null,
                ]);
            }

            if (! $dry) {
                $this->info(sprintf('  time clock: %s — 2 closed%s', $person->name, $i < 2 ? ', 1 open' : ''));
            }
        }
    }

    /**
     * Chat for the store screenshots (Apple, 2026-11-25): the App Review login
     * in a handful of chats with the invented staff, with recent messages, a
     * quick reply, a photo, a voice message and call lines.
     *
     * Insert-only like the rest. Its guard is the parent already having demo
     * history: once the review login shares a chat with any demo staffer, this
     * step has run and does nothing. The photo is drawn here and the voice note
     * is a quiet generated tone; neither is anybody's real file.
     */
    private function chat(bool $dry): void
    {
        $me = User::query()->where('email', 'apple@ezbizi.com')->first();
        $staff = User::query()->where('email', 'like', '%@'.self::STAFF_DOMAIN)->get()->keyBy('name');

        if ($me === null || $staff->count() < 4) {
            $this->line('  chat: skipped — needs apple@ezbizi.com and the demo staff');

            return;
        }

        $already = ChatMember::query()
            ->where('user_id', $me->id)
            ->whereIn('chat_id', ChatMember::query()->whereIn('user_id', $staff->pluck('id'))->select('chat_id'))
            ->exists();
        if ($already) {
            $this->line('  chat: already there');

            return;
        }
        if ($dry) {
            $this->line('  chat: would add 6 chats for apple@ezbizi.com');

            return;
        }

        $ada = $staff['Ada Vance'];
        $marcus = $staff['Marcus Iheme'];
        $priya = $staff['Priya Raghunathan'];
        $tomas = $staff['Tomas Bergqvist'];
        $rosa = $staff['Rosa Delgado'];
        $at = fn (int $minutesAgo) => now()->subMinutes($minutesAgo);

        $made = [];
        $make = function (string $type, array $people, ?string $name = null) use ($me, &$made): Chat {
            $chat = Chat::query()->create([
                'organization_id' => $this->organizationId,
                'type' => $type,
                'name' => $name,
                'direct_key' => $type === 'direct' ? Chat::directKey($me->id, $people[0]->id) : null,
                'created_by' => $me->id,
            ]);
            foreach ([$me, ...$people] as $i => $person) {
                ChatMember::query()->create([
                    'organization_id' => $this->organizationId,
                    'chat_id' => $chat->id,
                    'user_id' => $person->id,
                    'is_admin' => $type === 'group' && $i === 0,
                ]);
            }

            $made[] = $chat->id;

            return $chat;
        };

        $say = function (Chat $chat, User $from, int $minutesAgo, array $fields) {
            $message = new ChatMessage([
                'organization_id' => $this->organizationId,
                'chat_id' => $chat->id,
                'sender_id' => $from->id,
                'kind' => 'text',
                ...$fields,
            ]);
            $message->created_at = now()->subMinutes($minutesAgo);
            $message->updated_at = $message->created_at;
            $message->save();
            $chat->forceFill(['last_message_at' => $message->created_at, 'updated_at' => $message->created_at])->save();

            return $message;
        };

        $file = function (ChatMessage $message, User $by, string $name, string $mime, string $bytes, string $ext) {
            $files = app(FileStore::class);
            $disk = $files->defaultDisk();
            $path = $files->putContents($bytes, $files->directoryFor('attachments', $this->organizationId), $ext, $disk);
            (new Attachment([
                'organization_id' => $this->organizationId,
                'attachable_type' => 'chat_messages',
                'attachable_id' => $message->id,
                'original_name' => $name,
                'mime' => $mime,
                'uploaded_by_user_id' => $by->id,
            ]))->forceFill([
                'disk' => $disk, 'path' => $path, 'size_bytes' => strlen($bytes),
                'checksum' => hash('sha256', $bytes), 'encrypted' => false,
            ])->save();
        };

        // Ada, dispatch: the job of the day, a photo, a missed video call.
        $c = $make('direct', [$ada]);
        $say($c, $ada, 95, ['body' => 'Morning! You have the Elm St chimney cap first, then two sweeps in West Hartford.']);
        $say($c, $me, 92, ['body' => 'Got it, loading the van now']);
        $say($c, $ada, 60, ['kind' => 'call', 'meta' => ['status' => 'missed', 'video' => true, 'started_at' => $at(60)->toIso8601String(), 'joined' => [$ada->id]]]);
        $photo = $say($c, $ada, 38, ['kind' => 'image', 'body' => 'This is the cap the customer sent']);
        $file($photo, $ada, 'chimney-cap.png', 'image/png', $this->chimneyPicture(), 'png');
        $say($c, $me, 12, ['body' => 'On my way']);
        $say($c, $ada, 9, ['body' => 'Thanks! Customer says the side gate is open 👍', 'reactions' => [$me->id => '👍']]);

        // Marcus: a voice message and an answered call.
        $c = $make('direct', [$marcus]);
        $say($c, $marcus, 240, ['kind' => 'call', 'meta' => ['status' => 'answered', 'video' => false, 'started_at' => $at(240)->toIso8601String(), 'joined' => [$marcus->id, $me->id], 'duration' => 134]]);
        $voice = $say($c, $marcus, 47, ['kind' => 'voice', 'meta' => ['seconds' => 14]]);
        $file($voice, $marcus, 'voice.wav', 'audio/wav', $this->quietTone(14), 'wav');
        $say($c, $me, 44, ['body' => 'Running late, about 15 min']);

        // The crew group.
        $c = $make('group', [$ada, $marcus, $tomas], 'Hartford crew');
        $say($c, $me, 600, ['kind' => 'system', 'meta' => ['event' => 'created', 'name' => 'Hartford crew']]);
        $say($c, $tomas, 30, ['body' => 'Ladder is back at the shop']);
        $say($c, $marcus, 26, ['body' => 'Who has the 9" flue brush?']);
        $say($c, $me, 24, ['body' => 'In my van, I can drop it at noon']);
        $say($c, $ada, 20, ['body' => '*Reminder:* photos before and after on every job', 'reactions' => [$tomas->id => '🙏', $marcus->id => '👍']]);

        // Priya: unread, waiting on the reviewer.
        $c = $make('direct', [$priya]);
        $unread = $c->id;
        $say($c, $me, 180, ['body' => 'Need parts: two 13x13 caps and a damper']);
        $say($c, $priya, 5, ['body' => 'Ordered. Can you approve the PO when you are back?']);

        // Rosa: yesterday.
        $c = $make('direct', [$rosa]);
        $say($c, $rosa, 60 * 20, ['body' => 'Your hours for last week are approved']);
        $say($c, $me, 60 * 19, ['body' => 'Done']);

        // Dispatch group.
        $c = $make('group', [$ada, $rosa, $priya], 'Dispatch');
        $say($c, $rosa, 60 * 26, ['body' => 'Tomorrow is fully booked; overflow goes to Thursday']);
        $say($c, $me, 60 * 25, ['kind' => 'call', 'meta' => ['status' => 'answered', 'video' => true, 'started_at' => $at(60 * 25)->toIso8601String(), 'joined' => [$me->id, $ada->id, $rosa->id], 'duration' => 412]]);

        // Everyone has read everything, except the reviewer in Priya's chat,
        // which therefore shows as unread.
        $stamp = now()->format('Y-m-d H:i:s.u');
        ChatMember::query()->whereIn('chat_id', $made)
            ->where(fn ($q) => $q->where('chat_id', '!=', $unread)->orWhere('user_id', '!=', $me->id))
            ->update(['read_at' => $stamp, 'delivered_at' => $stamp]);

        $this->info('  chat: added 6 chats for apple@ezbizi.com');
    }

    /** A small drawn picture of a roof and a capped chimney (PNG), invented. */
    private function chimneyPicture(): string
    {
        $w = 640;
        $h = 480;
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            imageline($img, 0, $y, $w, $y, imagecolorallocate($img, (int) (120 + 90 * $t), (int) (170 + 60 * $t), (int) (230 + 20 * $t)));
        }
        $roof = imagecolorallocate($img, 70, 74, 82);
        imagefilledpolygon($img, [0, 480, 0, 330, 320, 180, 640, 330, 640, 480], $roof);
        $brick = imagecolorallocate($img, 168, 74, 52);
        $mortar = imagecolorallocate($img, 214, 196, 180);
        imagefilledrectangle($img, 380, 120, 470, 300, $brick);
        for ($y = 120; $y <= 300; $y += 18) {
            imageline($img, 380, $y, 470, $y, $mortar);
            $offset = (($y - 120) / 18) % 2 === 0 ? 0 : 22;
            for ($x = 380 + $offset; $x <= 470; $x += 44) {
                imageline($img, $x, $y, $x, min($y + 18, 300), $mortar);
            }
        }
        $steel = imagecolorallocate($img, 190, 196, 204);
        imagefilledrectangle($img, 368, 104, 482, 120, $steel);
        imagefilledrectangle($img, 384, 84, 466, 104, imagecolorallocate($img, 60, 64, 70));
        imagefilledrectangle($img, 360, 74, 490, 86, $steel);
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** A quiet, wordless tone of the given length (8 kHz mono WAV), invented. */
    private function quietTone(int $seconds): string
    {
        $rate = 8000;
        $samples = '';
        for ($i = 0; $i < $rate * $seconds; $i++) {
            $envelope = 0.5 + 0.5 * sin($i / $rate * 2 * M_PI * 3);
            $samples .= chr((int) (128 + 18 * $envelope * sin($i / $rate * 2 * M_PI * 220)));
        }
        $n = strlen($samples);

        return 'RIFF'.pack('V', 36 + $n).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate, 1, 8).'data'.pack('V', $n).$samples;
    }
}
