# Web Management — the chat switch, a Cities pane, and parity with A1 Tools

**Date:** 2026-09-18
**Status:** DESIGN. Nothing built. Sections 5 and 6 are ready to implement on approval; 7 onward describe tranche 1 and sketch what follows.
**Product:** EzBizi customer app (`app.ezbizi.com`), the WordPress plugin (`wordpress-plugin/ezbizi`, repo 1.11.0, directory 1.10.0), and the hosted website fleet.
**Asked for by:** Mia, 2026-09-18.

- On the widgets: *"i also want to have a toggle to turn it on or off, on being the default, (by turning it off, it basically disappears on the website, effectively disabling the chat)"*
- On the city pages: *"they just need to be separated from the current pages tab, and moved into a cities tab, i believe toula has already filled a lot of information on them already on ezbizi we dont want to destroy her work."*
- On the parity work: *"make a full review of what we are missing on ezbizi, against a1tools, and implement it on ezbizi ... as well as the wordpress plugin, making the elementor widgets"*, phased by what is actually in use, presentation as named presets, franchise address on the franchise row.

**Inventory that started it:** Toula's comparison of installed A1 Tools registrations against EzBizi's catalogue — 35 A1 Tools shortcodes with no EzBizi equivalent.

---

## 1. What this is

Three pieces of work that share one screen:

1. A switch that takes the live chat off a website entirely, defaulting to on.
2. A **Cities** pane inside SEO, so the 8,956 city pages Toula has filled stop
   being mixed into Pages.
3. The first tranche of A1 Tools parity: the fields and renderers that need no
   new data and no image hosting.

## 2. What this is not

- **Not a website editor.** Unchanged: EzBizi holds the facts, the customer's
  own WordPress renders them.
- **Not a migration of Toula's work.** §6 adds a way to *see* rows that already
  exist. No row is written, moved or deleted by this design.
- **Not parity with all 22 A1 Tools Elementor widgets.** §11 says which are
  deferred and why.
- **Not the media host.** Everything with a picture in it waits on the
  `media-hosting-for-wordpress-heroes` track. §10.
- **Not a copy of A1 Tools code.** Its plugin and its public API were read to
  learn the data shapes. Per `AGENTS.md`, none of it comes into this repo.

## 3. Settled decisions

| | |
|---|---|
| Chat switch default | **On.** A stored `false` is the only thing that turns it off. |
| How "off" reaches a site | **The `chat` block is omitted from the reader payload**, not sent as `enabled: false`. §5. |
| Form switch | **None.** Deliberate asymmetry, reasoned in §5. |
| Cities pane | A pane of SEO, **not a tab**. No new permission key. |
| What makes a page a city page | It holds a `city` value, or its WordPress author is a city-kind account. §6. |
| Hours widget | **No new field.** The canonical string `hours.dart` already writes is parsed server-side into a derived `hours_week`. §7.3. |
| Layout choices | **Named presets held in EzBizi**, chosen per instance: `[ezbizi_city_list preset="footer"]`. §8. |
| Franchise address | **New columns on `franchises`.** The franchise row becomes the record. §9. |
| New permissions | **None** in tranche 1. §9 adds no key either; editing a franchise is already an Organization grant. |
| Phasing | By what is on live pages and unblocked, owner's answer 2026-09-18. §4.1. |

## 4. The measurements this rests on

Taken 2026-09-18. Every later section leans on these, so they are written down
with their date; the box moves.

**The estate.** 111 hosts registered, 110 connected, 109 seen in the last
fifteen minutes. **110 report plugin 1.11.0** — so the fleet is current, and
`docs/ACTIVE-CONSTRAINTS.md` §14 (which says 1.9.1 on 110) is stale. The
directory publishes 1.10.0 with 100 active installs; 1.11.0 is not in it.

**Toula's page work.** 58,315 value rows across 109 sites, of which 56,363 are
page-scoped over 5,754 distinct paths. On page rows: `city` 8,956, `country`
8,956, `address` 8,956, **`group` 7,904**, `state` 6,739, `phone` 1,063,
`address_line1` 145. `author` and `category`: **zero rows each** — the two page
fields added from WordPress's own lists have never been used. Heaviest sites:
call.a-1chimney.com 609 pages, nj.a-1chimney.com 574, nj.everdoors.com 531,
nj.roofirst.com 529, ma.a-1chimney.com 425.

`group` is being used as a **region**: "South Jersey" 258, "North Jersey" 107,
"Southeast & Cape Cod" 93, "North Fulton", "East Metro", "Fairfield County".
Paths look like `/service-areas/fairburn-ga`.

**What A1 Tools actually holds**, from its own public API, per site:
before/after **103**, team **40**, stores **39** (9 for Everdoors), franchises
**1–2**, city pages **1,107** on nj.a-1chimney.com (172 Everdoors, 91 atl., 44
va.), content blocks **67** for a-1chimney.com. And **zero rows everywhere** for
`services`, `reviews`, `faqs` and `seo_locations`.

**What is on the live pages**, from the rendered HTML of six of them: Business
Hours (15–22 rows per page), Contact Info / Info Box (15–19 blocks), Social Icons
(21–25), CTA (up to 28 on Everdoors), Video Carousel (14 slides on every home
page), City List with accordions (17 on nj.), and A1's own Web Form on the
contact pages. EzBizi's chat renders on all six.

**The page-kind taxonomy.** Every fleet site files pages under WordPress author
accounts that name the kind, readable from the public REST API: `Single City`,
`SEO CITY`, `Location City` / `Single Location`, `Single Service`, `Single Post`,
`Privacy`, and the brand account for real pages. A1 Tools keys off exactly this.

### 4.1 Phasing

- **Tranche 1** — no new data, no media: §7.
- **Tranche 2** — data EzBizi already holds: reviews, services, store locator,
  franchise fields, FAQ. §9, sketched.
- **Tranche 3** — anything with an image. Blocked. §10.

## 5. The chat switch

### What it does

One switch at the top of the chat section in Web Management → Widgets. On by
default. Off means the bubble is not on the website at all — not "closed", not
"away", gone.

### Why "off" omits the block rather than sending a flag

`chat.enabled` is already on the wire, hardcoded `true` in
`app/Support/Web/WidgetDefaults.php:307`. Plugin versions **1.5.0 through 1.11.0
ignore it**, and the whole fleet is on 1.11.0 — so a new flag would change
nothing on any customer site until the plugin was released to the directory and
110 sites pressed Update. That is the two-channel trap described in
`docs/ACTIVE-CONSTRAINTS.md` §14, and this feature does not have to pay it.

The plugin already draws nothing when a widget's config block is **absent**:
`ezbizi_widget_config()` returns empty (`enquiries.php:84-116`), the shortcode
returns an empty string (`:302-316`), and the `wp_footer` auto-injector skips
(`:674-691`). That is the same path a refused token takes. So:

> **Off = the `chat` key is not in the payload.**

This works on every plugin from 1.2.0 onward, needs no plugin release, and takes
effect at the site's next refresh — within five minutes. When on, the block still
carries `enabled: true`, because 1.4.0 and earlier check it and those installs
may exist outside the fleet.

### Where the split lives

The same split `ValueResolver` already makes between readers and the editor:

- **Reader paths** — `WebWidget::resolvedFor()`, consumed by
  `PluginController::values()` (`:109`) and `PublicValuesController` — omit the
  `chat` key when it is off.
- **Editor path** — `WebWidget::forEditor()` keeps the block, with
  `enabled: false`, or the screen cannot draw the switch it needs to flip back.

`WidgetDefaults::resolve()` stops hardcoding `true` and reads the stored value,
defaulting to `true`. The comment at `:290-306` explaining why the key is
meaningless is replaced by one explaining why it is now meaningful, and why the
absence rather than the flag is what disables.

### Inheritance

Unchanged and worth stating because it is the surprising part: a site with no
`web_widgets` row of its own inherits the primary's. **Switching the chat off on
the primary switches it off on every site that follows it.** A single site opts
out by having its own row — which is what the existing "stop following the
primary" control already does.

### Why the form gets no switch

The chat **injects itself into the footer of every page**; the form appears only
where somebody placed it. Taking the form off a site is deleting a shortcode from
one page, which is already how it works and already reversible. A global switch
for it would be a second, invisible way to blank something a person deliberately
placed — which is the exact failure that had the original switches removed on
2026-09-15 ("get rid of the switches, they should just be on"). The chat is the
one widget that genuinely needs a site-wide off, because it is the one that puts
itself everywhere.

### Files

`app/Support/Web/WidgetDefaults.php`, `app/Models/WebWidget.php`,
`client/lib/screens/web/widgets_tab.dart`. No migration — widget config is
already a JSON column.

### Tests

- `tests/Feature/Web/WebWidgetsTest.php` — default is on for a site with no row;
  a stored `false` round-trips through `forEditor`; `resolvedFor` **has no `chat`
  key** when off and still has `form` and `style`.
- `tests/Feature/Web/WebManagementTest.php` — the plugin payload for a site with
  the chat off contains no `chat` block; with it on, the block carries
  `enabled: true`.
- `wordpress-plugin/tests/plugin-check.php` — an absent `chat` block renders
  nothing from `[ezbizi_chat]` and injects nothing at `wp_footer`. This asserts
  the property the whole design rests on, against the plugin **as published**.
- `client/test/web_widgets_test.dart` — the switch renders on, flips, and the
  chat pane's other controls stay reachable while off (you edit the wording
  before you turn it back on).

## 6. The Cities pane

### What it does

A sixth pane in SEO's section strip — General, Social links, Pages, **Cities**,
Posts, Templates — listing one site's city pages grouped by the region Toula
already types into `group`, with a search box and a count per group. Opening one
opens `PageEditor`, the screen she already uses. Pages stops listing them.

### What makes a page a city page

Two signals, unioned, and neither is a new column:

1. **It holds a `city` value.** 8,956 pages say so today. Her data defines the
   list, which is the same argument `PageFields::forSite()` already makes about
   reading the keys from the values themselves rather than keeping a second list.
2. **Its WordPress author is a city-kind account** — display name containing
   "city", case-insensitive, which catches `Single City`, `SEO CITY` and
   `Location City` across the fleet. `Single Location` does not contain it and is
   a different kind, deliberately: A1 Tools separates city pages from location
   pages and so should we.

Signal 1 is what the pane lists by default: it is instant, needs no call to the
customer's website, and still works when their site is down. Signal 2 powers one
explicit action — **"Find city pages on the website"** — which walks the live
page list and shows the city pages EzBizi holds nothing for yet. That makes the
pane a worklist rather than a mirror, which is the job Toula is actually doing.

It is an action rather than an automatic load because the walk costs seven
round-trips to a 609-page site at WordPress's 100-per-page ceiling, and a pane
that takes four seconds to open is a pane nobody opens.

### One classifier, not two

`app/Support/Web/PageKinds.php` — new, small, pure — answers "what kind of page
is this?" from a path, its values and its author name. Both `ContentController`
(so Pages can exclude what Cities claims) and the new cities read call it. Two
independent rules would drift, and the failure is a page in both lists or
neither.

### The endpoint

    GET /api/v1/web/sites/{site}/cities

Returns, per city page: `path`, `city`, `state`, `phone`, `group`, `title` and
`link` when known, and how many of the city keys are filled. Built from
`web_values` alone, so it cannot fail because a customer's website is slow.
Gated on `web_management.seo.view`, beside the other SEO reads in
`routes/api.php`.

`?scan=1` adds the author-driven walk described above and marks rows EzBizi holds
no values for. Failure there is reported as a note on the pane, never as an empty
list — the values half has already succeeded by then.

### What does not change

`web_values`, its shape, its contents, and `PageEditor`. Toula's 8,956 rows are
read, never rewritten. Pages keeps its live WordPress read; it just filters.

### Files

New: `app/Support/Web/PageKinds.php`, a controller method for the cities read,
`client/lib/screens/web/cities_pane.dart`. Changed:
`app/Http/Controllers/Api/Web/ContentController.php`, `routes/api.php`,
`client/lib/screens/web/seo_tab.dart` (one more section), and
`client/lib/screens/web/content_tab.dart` (exclusion).

### Tests

- `PageKinds` unit tests: a page with a `city` value, a page authored by
  "SEO CITY", a page that is both, `Single Location` **not** a city, and a
  page that is neither.
- Feature: the cities read groups by `group`, puts pages with no group under
  Ungrouped, and returns rows for a site whose WordPress is unreachable.
- Feature: Pages excludes what Cities claims, and tenant isolation holds — the
  existing `WebTenantIsolationTest` pattern, extended to the new route.
- Widget: the pane renders groups collapsed with counts, search filters within a
  group, and tapping a row opens the editor.

## 7. Tranche 1 — the fields and renderers that need nothing new

### 7.1 Five new catalogue keys

One line each in `SeoFields::general()`, and each becomes a working shortcode on
every installed plugin at the next refresh, with no plugin release — which is the
property the catalogue exists for.

| Key | Shortcode | Requirement | Replaces |
|---|---|---|---|
| `phone_secondary` | `[ezbizi_phone_secondary]` | conditional | `[a1tools_phone_secondary]` |
| `email_secondary` | `[ezbizi_email_secondary]` | optional | `[a1tools_email_secondary]` |
| `review_link` | `[ezbizi_review_link]` | optional | `[a1tools_review_link]` |
| `latitude` | `[ezbizi_latitude]` | optional | feeds `[ezbizi_google_map]` |
| `longitude` | `[ezbizi_longitude]` | optional | feeds `[ezbizi_google_map]` |

These two are **this site's own** coordinates, which is what a map on this site
needs. `[a1tools_franchise_latitude]` and `[a1tools_franchise_longitude]` are a
branch's, and they come from §9 rather than from here — a site and a franchise
are not the same place, and one site can front several branches.

`review_link` takes the `_link` suffix rather than A1's `_url` so it goes through
`esc_url` under the rule already in `ezbizi_is_link_key()`.

### 7.2 Three renderers over values we already publish

- **`[ezbizi_social_links]`** — the combined widget. EzBizi has all fifteen
  network keys and no way to render them as a row of icons, which is what every
  live page uses. Presets per §8.
- **`[ezbizi_google_map]`** — an embedded map. `maps_url` is a share link and
  cannot be framed, so the iframe is built from `latitude`/`longitude` when
  present and the block degrades to a link to `maps_url` when they are not.
- **`[ezbizi_post_title]`** — WordPress's own title, so a shared template can
  name the page it is on. Renders in the plugin with no server data at all.

### 7.3 The hours widget, without a new field

`hours` stays a **string**. `client/lib/screens/web/hours.dart:5-24` is explicit
about why: installs in the wild already cache and render one, and `formatWeek` /
`parseWeek` are exact inverses precisely so the grid is an editor over a
canonical string rather than a new data type.

So the Business Hours widget gets its structure the way `address` and
`website_url` already get theirs — **derived on the way out**. A PHP port of
`parseWeek` adds `hours_week` to the payload: seven day entries, or absent when
the stored string is free text somebody typed ("by appointment November to
March"). `[ezbizi_hours]` is unchanged and still prints the string.
`[ezbizi_hours_table]` renders the structured form and falls back to the string
when `hours_week` is absent, so a site that never used the grid still renders.

The PHP port and the Dart original are tested against the same table of cases in
both languages. Two parsers that disagree would put one week on the screen and a
different one on the website.

## 8. Presets, and why layout is not a shortcode argument

A1 Tools carries presentation per instance:
`[a1tools_city_list layout="accordion" columns="3" accordion_mode="multiple" show_search="yes"]`,
`[a1tools_social_links style="outline" shape="circle" size="40" hover="scale"]`.
EzBizi decided the opposite when the chat and form shipped: shortcodes take no
arguments and everything is set once in EzBizi, so a company with six subdomains
does not set its colours six times, differently, eventually.

Both cannot be true for a widget Toula has to drop onto a page that expects a
particular shape. The resolution:

> A **preset** is a named bundle of presentation, stored in EzBizi beside the
> widget config. A page chooses one by name: `[ezbizi_city_list preset="footer"]`.

The name is the only argument. Every value inside it is edited in EzBizi and
reaches every site at once, so the "one change" property survives; a page can
still differ from another page, which is what the existing pages need. A missing
or misspelled preset falls back to the site's default preset rather than
rendering nothing — a live page must not go blank over a typo.

Presets live in the same `web_widgets.config` JSON, under a `presets` key, so
they inherit from the primary site exactly as the chat and form already do. No
migration.

## 9. Franchise address — tranche 2's first piece

Twelve of Toula's 35 are franchise fields, and `franchises` holds only `name`,
`timezone`, `currency` and `is_active` — no address, no phone, no geo. The
decision taken 2026-09-18 is that **the franchise row is the record**: a
migration adds `address_line1`, `address_line2`, `city`, `state`, `postal_code`,
`country`, `phone`, `latitude`, `longitude`, `google_maps_url`.

**Why not read it from the Google Business Profile listing**, which already holds
address, phone and hours and can be linked to a franchise: a franchise with no
listing would publish nothing, and the address of a branch is a fact about the
business rather than a fact about its Google listing. The listing stays what it
is — a copy of what Google holds.

Editing lands in Organization → Structure, where franchises are already created
and renamed; `FranchisesController::update` accepts three fields today and grows
these. Publishing them is a separate question from holding them, and is where the
`a1tools_franchise*` shortcodes get their source; the shape of that read is for
tranche 2's own spec section, not this one.

## 10. Tranche 3 is blocked, and by exactly one thing

Team, Before & After, Gallery, service images, service icons and store images all
need a **public image URL**, and EzBizi has none. `attachments`, avatars and
branding logos are all served behind Sanctum; the only unauthenticated image path
is the ephemeral signed URL for the Instagram crawler. There is already a board
track for this (`media-hosting-for-wordpress-heroes`, todo) with the durability
decision unwritten.

So tranche 3 does not get designed here. It gets one sentence: it is the first
customer of that track, and its requirements are a stable public URL, a cache
header, byte ranges, and a size ceiling per organization.

## 11. Deferred, with reasons

- **`[a1tools_services]`, `[a1tools_reviews]`, `[a1tools_review_widget]`,
  `[a1tools_faq]` and the five `service_*` fields.** Zero rows on every A1 site
  checked. EzBizi's own `reviews` and `services` tables hold real data, so the
  renderers in tranche 2 read ours rather than reproducing empty containers.
- **The 1,107 A1 city-page rows on nj.a-1chimney.com.** Toula is already filling
  EzBizi directly (574 pages on that host), so this is a question about
  reconciling two half-filled sets, not a widget. Its own decision.
- **A1's 67 slug-named content blocks against EzBizi's 30 numbered templates.**
  Both a ceiling and a naming mismatch. Its own decision.
- **Video Carousel, CTA, Info Box, Gallery, Smiley Reviews, Web Form.** Heavily
  used on live pages and absent from Toula's list, because she compared shortcode
  registrations and these are Elementor widgets. They are real work and they are
  not in tranche 1.
- **`[a1tools_ekomi_seal]`, `[a1tools_viewpoint]`.** A third-party seal and a
  field with no EzBizi equivalent concept. Ask Toula what the viewpoint is for
  before building it.

## 12. The Elementor side

Every new renderer in §7.2 and §8 gets an Elementor widget alongside its
shortcode, following `elementor-widgets.php`: a name, a title, an icon, the
General category, `is_dynamic_content()`, an editor placeholder, and a published
render that calls the shortcode. No styling controls — the preset name is the
only control, for the reason in §8.

## 13. The plugin release, and what it costs

§5 needs no plugin release, which is the point of it. §7.2, §7.3 and §12 do: they
are new shortcodes and new markup. That release is **1.12.0**, and it reaches
customer sites through the directory and each site's own Update — except our own
fleet, which is at 1.11.0 and which we host.

Before saying any of this is live, ask which half was measured:

    SELECT plugin_version, COUNT(*) FROM web_sites GROUP BY plugin_version;

1.11.0 is also not in the directory yet. That is a separate open item, already on
the board as `wp-publish-1-11-0`.

## 14. Open questions

- **For Toula:** what is `[a1tools_viewpoint]` for? It is the one field whose
  purpose is not legible from the code or the data.
- **For Toula:** `author` and `category` page fields have zero rows across 109
  sites. Should they stay on the page editor?
- **For Jose:** whether the plugin should opt into WordPress auto-updates. Raised
  2026-09-17 in `ACTIVE-CONSTRAINTS.md` §14 and still his — it means EzBizi code
  installing itself on customer sites unattended. Every tranche after 1 is slower
  while it is open.
