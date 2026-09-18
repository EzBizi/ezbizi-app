<?php

namespace Tests\Feature\Web;

use App\Support\Web\PageKinds;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What makes a page a city page — the one rule Pages and Cities both call.
 *
 * It is one class so the two lists cannot drift apart. These tests are the
 * cheapest place to pin the edges, and two of them are edges that a hard-coded
 * roster of author names would have got wrong on the fleet as it stands.
 */
class PageKindsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function authorNames(): array
    {
        return [
            // Read off the fleet on 2026-09-18.
            'Single City' => ['Single City', true],
            'SEO CITY, shouting' => ['SEO CITY', true],
            'Location City, which says City' => ['Location City', true],

            /*
             * `Single Location` is NOT a city. A1 Tools keeps city pages and
             * location pages apart — different shortcodes, different lists —
             * and nj.a-1chimney.com uses this name where atl. uses
             * `Location City`. Folding them together would put a branch's
             * address pages into a list of towns.
             */
            'Single Location' => ['Single Location', false],
            'Single Service' => ['Single Service', false],
            'Single Post' => ['Single Post', false],
            'the brand account' => ['A1Chimney', false],
            'Privacy' => ['Privacy', false],

            /*
             * The reason the match is word-boundaried rather than a substring.
             * A person called Felicity authoring the about page must not turn
             * it into a town, and `str_contains` would.
             */
            'a person whose name contains it' => ['Felicity Barnes', false],
            'capacity' => ['Capacity Planning', false],

            'nothing at all' => ['', false],
            'spaces' => ['   ', false],
        ];
    }

    #[DataProvider('authorNames')]
    public function test_which_author_accounts_name_the_city_kind(string $name, bool $isCity): void
    {
        $this->assertSame($isCity, PageKinds::authorNamesCity($name));
    }

    public function test_a_filled_city_value_makes_a_city_page(): void
    {
        $this->assertTrue(PageKinds::valuesNameCity(['city' => 'Toms River']));
        $this->assertSame(PageKinds::CITY, PageKinds::of(['city' => 'Toms River']));
    }

    /**
     * A blank is not a statement.
     *
     * An empty string is what a box looks like after somebody clears it, and a
     * path whose only row is an empty `city` would otherwise leave the Pages
     * list for ever with nothing to show for itself in Cities.
     */
    public function test_an_empty_city_is_not_a_city_page(): void
    {
        $this->assertFalse(PageKinds::valuesNameCity(['city' => '']));
        $this->assertFalse(PageKinds::valuesNameCity(['city' => '   ']));
        $this->assertFalse(PageKinds::valuesNameCity([]));
        $this->assertSame(PageKinds::PAGE, PageKinds::of(['phone' => '973-555-0111']));
    }

    /** Either signal alone is enough, which is the whole point of having two. */
    public function test_either_signal_is_enough(): void
    {
        // She typed a city in; the brand account authored it.
        $this->assertSame(PageKinds::CITY, PageKinds::of(['city' => 'Brick'], 'A1Chimney'));

        // Authored by SEO CITY; nobody has filled anything in yet. This is the
        // row that makes the Cities pane a worklist.
        $this->assertSame(PageKinds::CITY, PageKinds::of([], 'SEO CITY'));

        $this->assertSame(PageKinds::PAGE, PageKinds::of([], 'A1Chimney'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function links(): array
    {
        return [
            'a trailing slash' => ['https://nj.a-1chimney.com/service-areas/toms-river-nj/', '/service-areas/toms-river-nj'],
            'no trailing slash' => ['https://nj.a-1chimney.com/service-areas/toms-river-nj', '/service-areas/toms-river-nj'],
            'the home page' => ['https://nj.a-1chimney.com/', '/'],
            'a query string' => ['https://nj.a-1chimney.com/about-us/?utm=x', '/about-us'],
            'an address-style slug' => [
                'https://nj.a-1chimney.com/52-hyers-st-toms-river-nj-08753/',
                '/52-hyers-st-toms-river-nj-08753',
            ],
        ];
    }

    /**
     * A permalink, in the form values are keyed by.
     *
     * This is where the live half and the stored half of the feature meet, and
     * a trailing slash on one side of that comparison is a page that appears in
     * both lists — so the normalisation has its own test rather than being
     * trusted inside a bigger one.
     */
    #[DataProvider('links')]
    public function test_a_permalink_becomes_the_path_values_are_keyed_by(string $link, string $path): void
    {
        $this->assertSame($path, PageKinds::pathOf($link));
    }
}
