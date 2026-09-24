<?php

namespace App\Console\Commands;

use App\Models\AssistantEntry;
use App\Models\Organization;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Put A-1's staff-bot answers into its AI Assistance Library.
 *
 * Until 2026-09-24 these were constants in WhatsAppStaffBot, answered to every
 * sender on the bot number. The Library replaced them so the business edits
 * its own replies; this moves the answers A-1 already had into it ONCE, so
 * the bot says the same things the morning after the release as the night
 * before. Deliberately not a fallback in code: a built-in answer would be
 * every tenant's, and this one holds A-1's dealer code and what A-1 pays each
 * lender.
 *
 * ── Safe to run twice, and never over an edit ────────────────────
 *
 * An answer is matched on its section and title and created only when
 * missing; a setting is written only while it still holds its default. So a
 * re-run after somebody has reworded an answer, or renamed the assistant,
 * leaves their words alone.
 *
 * ── Where the answers came from ──────────────────────────────────
 *
 * NFPA 211 and CSIA: stated in our own words from published sources, 2026-09-24
 * - NFPA 211 as CSIA quotes it, and CSIA's Level 2 SOP (Masonry Fireplace,
 * rev. 4/5/19). Level 3 is named and not described, because no source in hand
 * described it. Finance: Jose's own guidance, 2026-09-24, STAFF ONLY - it says
 * what A-1 pays each lender. A-1's certified people should read the code
 * answers before techs rely on them.
 */
class AssistantImportA1 extends Command
{
    protected $signature = 'assistant:import-a1
        {--organization= : Organization name, e.g. A1-Group}';

    protected $description = "Load A-1's staff-bot answers into its AI Assistance Library";

    private const FOOTER = "\n\n_Our summary of NFPA 211 and CSIA guidance, in our own words. "
        .'Confirm against the current NFPA 211 edition adopted where you are working._';

    private const SETTINGS = [
        'ai_assistance.name' => 'A1 Chimney Bot',
        'ai_assistance.welcome' => "Welcome to the A1 Chimney staff assistant.\n\n"
            .'Choose an option below for codes and standards, training resources, '
            .'or branch contact details.',
        'ai_assistance.location_group' => 'A1 Chimney',
    ];

    public function handle(TenantContext $tenant, SettingsResolver $settings, SettingRegistry $registry): int
    {
        $organization = Organization::query()->where('name', $this->option('organization'))->first();

        if ($organization === null) {
            $this->error('Organization not found: '.$this->option('organization'));

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($organization, $settings, $registry) {
            $created = 0;

            foreach (self::entries() as $position => $entry) {
                $row = AssistantEntry::query()->firstOrCreate(
                    ['company_id' => null, 'section' => $entry['section'], 'title' => $entry['title']],
                    [...$entry, 'position' => $position],
                );
                $created += (int) $row->wasRecentlyCreated;
            }

            foreach (self::SETTINGS as $key => $value) {
                $default = $registry->getOrFail($key)->default;

                if ($settings->get($key, $organization) === $default) {
                    $settings->set($key, $organization, $value);
                }
            }

            $this->info("{$created} answer(s) added; ".(count(self::entries()) - $created).' already there.');

            return self::SUCCESS;
        });
    }

    /** @return list<array{section: string, title: string, description: string, body: string, audience: string}> */
    public static function entries(): array
    {
        $nfpa = fn (string $title, string $description, string $body) => [
            'section' => 'NFPA 211', 'title' => $title, 'description' => $description,
            'body' => $body.self::FOOTER, 'audience' => AssistantEntry::AUDIENCE_PUBLIC,
        ];

        return [
            $nfpa('How often to inspect', 'The annual requirement', "*How often to inspect*\n\n"
                .'NFPA 211 requires chimneys, fireplaces and vents to be inspected at least '
                ."once a year.\n\n"
                ."Each inspection checks three things:\n"
                ."• the structure is sound\n"
                ."• it is free of deposits, such as creosote\n"
                .'• clearances to combustibles are correct'),
            $nfpa('Inspection levels', 'Level 1, 2 and 3', "*Inspection levels*\n\n"
                .'NFPA 211 defines three levels. Its Table 15.2.1 decides which one a job '
                ."needs.\n\n"
                .'*Level 1* - to confirm a chimney is still fit for continued use, under the '
                ."same conditions and with the same or a similar appliance.\n\n"
                .'*Level 2* - when conditions are changing or something may have caused '
                ."damage. See \"When a Level 2 is needed\".\n\n"
                .'*Level 3* - also defined by NFPA 211. Confirm its requirements against the '
                .'standard before relying on them.'),
            $nfpa('When a Level 2 is needed', 'The triggers', "*When a Level 2 inspection is needed*\n\n"
                ."Move up to a Level 2 when:\n"
                .'• a new appliance is added, or one is replaced with a different fuel, '
                ."efficiency or type\n"
                ."• a Level 1 can't settle whether the venting system is serviceable\n"
                ."• the property is being sold\n"
                ."• the chimney is going to be lined or relined\n"
                .'• there has been a building or chimney fire, a weather or seismic event, or '
                .'any malfunction likely to have damaged the chimney'),
            $nfpa('What a Level 2 covers', 'Everything in a Level 1, plus more', "*What a Level 2 inspection covers*\n\n"
                ."Everything in a Level 1, plus:\n"
                .'• every accessible part of the fireplace, chimney and connected appliances '
                ."- tools and ladders may be needed\n"
                ."• the roof\n"
                ."• accessible attics, basements and crawl spaces\n"
                ."• every flue in the chimney, with its appliances\n"
                ."• a *video scan* of the chimney interior - this is REQUIRED\n\n"
                .'If you cannot scan it (flue size, offsets) or cannot reach an area, or the '
                .'client asks you not to, write that in the report with the reason.'),
            $nfpa('Flue sizing', 'Against the fireplace opening', "*Flue sizing for a fireplace*\n\n"
                ."NFPA 211 sizes the flue against the area of the fireplace opening:\n"
                ."• round flue - 1/12 of the opening area\n"
                ."• square or standard rectangular flue - 1/10\n"
                .'• rectangular flue with a 2:1 aspect ratio or more - 1/8'),
            $nfpa('Crown bond break', 'Where the top tile meets the crown', "*Crown bond break*\n\n"
                .'NFPA 211 requires a bond break where the top flue tile passes through the '
                .'crown, so the flue can expand upward without cracking the crown.'),
            [
                'section' => 'CSIA', 'title' => 'CSIA', 'description' => 'Exam and continuing education',
                'audience' => AssistantEntry::AUDIENCE_PUBLIC,
                'body' => "*CSIA - Chimney Safety Institute of America*\n\n"
                    .'*Certification exam* - based on the Chimney & Venting Essentials manual and '
                    ."NFPA 211.\n\n"
                    .'*Continuing education* - CSIA runs free online Codes & Standards quizzes '
                    .'during the first week of each month. Passing them earns CSIA CEUs.'
                    .self::FOOTER,
            ],
            [
                'section' => 'Finance options', 'title' => 'Finance options',
                'description' => 'Lenders to offer, and in what order',
                'audience' => AssistantEntry::AUDIENCE_STAFF,
                'body' => "💰 *Finance options*\n\n"
                    .'Always offer financing when it fits. Many clients qualify for 0% APR promotional '
                    ."financing, which helps close more sales and raises the average job size.\n\n"
                    ."Use them in this order, based on the cost to the company:\n\n"
                    ."1️⃣ *Acorn Finance* - preferred\n"
                    ."✅ No dealer fees to A1\n"
                    ."https://www.acornfinance.com/pre-qualify/?d=BLEBW&utm_medium=web_pre_qual_link_copy_welcome\n\n"
                    ."2️⃣ *Enhancify* - second choice\n"
                    ."✅ Covered by our annual subscription\n"
                    ."https://www.a-1chimney.com/about-us/financing/\n\n"
                    ."3️⃣ *Wisetack* - third choice\n"
                    ."⚠️ We pay a 3.9% flat fee on every financed job. Use only if the first two don't suit.\n"
                    .'Send the prequalify link from the Workiz invoice.',
            ],
        ];
    }
}
