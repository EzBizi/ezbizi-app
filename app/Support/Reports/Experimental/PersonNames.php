<?php

namespace App\Support\Reports\Experimental;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * One person's name across RingCentral and Workiz, from data rather than code
 * (Jose, 2026-11-04: "Shouldnt u be able to update them without an update?").
 * Every pair had been a constant, so each new spelling waited for a release.
 *
 * In order: a pair stored for the organization (call_report_name_links,
 * somebody's word: "Jay Ticoit" is "Dieuve Ticoit"); a name Workiz's team list
 * holds as it is; otherwise the one Workiz team member with the same first and
 * last word, middle names and initials aside ("Cgrey Lumacad" is "Cgrey S.
 * Lumacad"). Two Workiz people with that first and last word match neither,
 * and a suffix is a word ("Rolando De Lara Jr" needs a stored pair), so two
 * real people are never merged by resemblance alone.
 */
final class PersonNames
{
    /** @var array<string, true> */
    private array $roster = [];

    /** @var array<string, list<string>> first-and-last key => Workiz names */
    private array $byKey = [];

    /**
     * @param  array<string, string>  $links  phone spelling => name to show
     * @param  list<string>  $roster  Workiz team members' names
     */
    public function __construct(private readonly array $links, array $roster)
    {
        foreach ($roster as $name) {
            $name = self::tidy($name);
            if ($name === '' || isset($this->roster[$name])) {
                continue;
            }
            $this->roster[$name] = true;
            if (($key = self::key($name)) !== '') {
                $this->byKey[$key][] = $name;
            }
        }
    }

    /** The tenant's pairs and Workiz team list; nothing outside a tenant. */
    public static function forTenant(): self
    {
        $org = app(TenantContext::class)->get()?->getKey();
        if ($org === null) {
            return new self([], []);
        }

        return new self(
            DB::table('call_report_name_links')->where('organization_id', $org)->pluck('name', 'phone_name')->all(),
            DB::table('workiz_team_members')->where('organization_id', $org)->distinct()->pluck('name')->map(fn ($n) => (string) $n)->all(),
        );
    }

    public function canonical(?string $name): ?string
    {
        // A blank Workiz creator is nobody, not a person named "" (2026-11-04).
        if ($name === null || trim($name) === '') {
            return null;
        }
        // Spaces as a person types them ("Angel  F. Mozol" in Workiz).
        $name = self::tidy($name);
        if (isset($this->links[$name])) {
            return $this->links[$name];
        }
        if (isset($this->roster[$name])) {
            return $name;
        }
        $hits = $this->byKey[self::key($name)] ?? [];

        return count($hits) === 1 ? $hits[0] : $name;
    }

    /** Whether Workiz's team list holds the name as shown. */
    public function inWorkiz(string $name): bool
    {
        return isset($this->roster[self::tidy($name)]);
    }

    private static function tidy(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** First and last word, lower case, initials and punctuation aside; '' for one word. */
    private static function key(string $name): string
    {
        $words = array_values(array_filter(
            preg_split('/\s+/', mb_strtolower((string) preg_replace('/[^\p{L}\s-]/u', ' ', $name))) ?: [],
            fn ($w) => mb_strlen($w) > 1,
        ));

        return count($words) < 2 ? '' : $words[0].' '.$words[count($words) - 1];
    }
}
