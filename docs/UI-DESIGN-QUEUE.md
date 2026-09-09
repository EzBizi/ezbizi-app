# UI/UX — the queue, and what has been settled

Companion to `docs/MODULE-OWNERS.md`, written for the same reason and after the same
failure: in ninety minutes on 2026-08-31 the Phone, Fleet, Customers and rules-desk
sessions all ended, and four design briefs existed only in one session's context.
A brief that lives in a session is a brief that will be re-gathered from scratch.

**UI/UX owns the decision, not the module** — the rules desk's wording, and better
than the one first broadcast. A module session keeps its own screen files for
bugfixes, copy and wiring; anything changing **layout** goes through design.
The four cross-cutting files are design's outright: `client/lib/core/theme.dart`,
`module_screens.dart`, `dashboard_panels.dart`, `ui_strings.dart` — *styling* in them,
not registry rows. A one-line `key → icon` or `key → screen` entry is a registration
and is the module session's to make.

**Process** (`StartUp.md` §6, which predates the role): for a new tab or major screen,
produce several alternatives as HTML mockups in the app's own chrome with the real
palette and real data, **numbered so the owner answers by number**. Worked examples
live in `docs/previews/`. Jose answers by number.

---

## Settled rules

**A preview file is the SCREEN, not a document about the screen.** Jose, 2026-09-02:
*"you did the screen previews wrong, I need to see the actual UI in the html."* The
Suggestions galleries opened with about eighty lines of reasoning — a locked/found/ask
stack and a caption arguing the trade-offs — with the drawn UI below all of it, so
judging a layout meant reading an argument first. **It is the house style that was
wrong, not one session's file:** every gallery in `docs/previews/` before this one is
built that way, including the worked examples this doc points new sessions at. What the
file carries is the numbered picker, one title line, and the drawn screens. Everything
else moves into the report that goes to him alongside the files — it is good reasoning
and it is not the artifact. CSS comments are exempt: they cost a reader nothing, and
they are where a palette's provenance belongs.

> The sharper version, because it generalises past previews: **the reasoning that
> produced an artifact is not part of the artifact.** A drawing that has to be prefaced
> is a drawing that has not been made yet.

**Prominence is the module's, arrangement is design's.** Changing how loudly an
existing component states a state — grey caption to red banner, adding a count,
naming a state the screen left silent — stays with the module session and ships
immediately. A new component, a new section, or a new arrangement goes to design.
The carve-out exists because that change is often *the correctness fix itself*, and a
design round trip on a defect's critical path costs more than it buys. Phone's
evidence: five production lines went to voicemail for months because that state was
disclosed in grey secondary text. Condition: tell design after shipping, so the same
state does not become a red banner in one module and a grey pill in another.

**The vocabulary for that.** `errorContainer` + a **count** for "this is broken and
nobody noticed"; inline error-coloured text for a single row. Set by Phone, which
shipped four instances of it in one day.

**Rail width follows the longest thing the rail lists.** Not a single constant. Web
Management is 288/900 because a subdomain like `nj.a-1chimney.com` truncates below
280 and its detail pane needs ~560 for a field row plus its shortcode chip; Fleet and
Phone are 200/600 because they list short names and have no chip. Two measured
reasons, not two accidents.

**An editable field may not hide when empty.** It could then never be filled. Fleet
is the case: 6 of 21 Identity fields are empty across the entire production fleet and
every one edits in place. Progressive disclosure has to be something the user
controls, never something the data decides.

**Assert `findsNothing` for `AlertDialog` and `showDialog` in every screen's widget
test.** A module once shipped two dialogs against the no-pop-ups rule and an audit
caught them weeks later; screens written since carry the assertion. It is the only
reason the rule holds anywhere it holds. *Proposed as a house standard — the rules
desk session ended before it could be recorded there.*

**Name the design in the commit message.** The deploy desk's ask, and it costs
nothing:

    design: docs/previews/<file>.html #<n>

Approved designs are numbered layouts in a committed file, so the pair is unambiguous.
Without it the deploy desk cannot tell Jose what shipped, and is right not to guess.

**Design sign-off is not a deploy gate.** The deploy desk gates on tests, migrations
and freeze safety — things it can verify. It declined to add "has this been through
design?" on a peer's say-so, which is correct: a role must not be enforceable by the
session that holds it. Only Jose can put it at the gate.

**Never redefine a field or a number in place — add a new one.** Five distinct
instances in a single day: summing per-guide `overdue` and calling the result people;
a safety note that was right for the wrong reason; an offer to redefine
`people_due_for_retaking` from *already lapsed* to *lapsing within N days*; and a ✅ in
this file's own queue meaning both "being built" and "nobody is touching this". Each
time, **the number stays plausible and the meaning moves**, so nothing announces the
change and every existing reader is quietly wrong. If the meaning shifts, the name
shifts.

> **The fifth, found in a JSON key by the Phone session, is the cleanest example
> of the shape.** `TwimlRenderer::describe()` gives every step a `usable` bool —
> but on the *menu* step it means "this step does not break the call", and on the
> *answer* step it means "reaches somebody". Two meanings, one key. So a design
> asking for `usable` as its skip flag would have rendered the one step it was
> drawn for — a menu that is configured and silently skipped — as perfectly fine.
> The fix is a distinct `skipped` bool, not a widened `usable`: **when a key has to
> answer a second question, it gets a second key.**

> **Why writing the rule down twice did not stop it happening in the file that
> contains it:** this failure is markedly easier to see in someone else's data than in
> your own status column. Nobody audits their own progress markers — a tick you wrote
> reads as the thing you meant when you wrote it, not as the thing it now says. So the
> rule needs a habit attached, not just a statement: when a marker could describe two
> states, split it before anyone has to ask which one it means.

**An approval is not a delivery, and design builds what design draws.** Jose,
2026-09-01: *"if you are in charge of ui-ux, then you do it, you build everything
that i ask you to, you don't delegate."* Handing an approved layout back to a
module session is how approvals rot. An audit that day of all **28 galleries in
`docs/previews/` against the shipped code** — the first one ever done — found
three approved designs never built: the sidebar module search, the Your account
rebuild, and the call desk proportions. Only **6 commits of 28 galleries** cite
the design they implement, which is why nobody could see it: there was no way to
check approved-against-shipped except reading every screen.

**A release note is a claim about the CODE. Write it from the code.** The same
audit found `0.0.0126` claiming *"the call desk now gives each column a fair
share of the window"* when no commit had ever touched those widths. It was
written from the approved design and shipped to production, so the app told
people a change had landed while the screen did the old thing. False when
written, so it was deleted rather than superseded — there is no history to keep
when nothing happened. Fixed in `9f72bcf`.

**Design notes written from a mockup are guesses about the code.** All three
"carry into the build" notes on Your account were wrong, and reading the source
caught every one. The date of birth was already a typed field whose calendar
opens inline rather than as a dialog — better than the masked field specified.
Gender as "chips only" would have deleted the ability to answer at all when the
answer is not one of four. And "You can act on repeats itself" was wrong about
the cause: two companies can each operate a Connecticut, so those are different
places sharing a name, and deduping would have hidden real grants. Same failure
as the Voicemail pane: classify by reading it, not by looking at the picture.

**Every module page opens under its own name, and the shell draws it.** Approved
2026-09-01 as `docs/previews/module-page-header-layouts.html` — desktop layout 1
with mobile layout 5, and **the template every future module follows**. The
header is `ModulePageHeader`, rendered once by `HomeShell._titled()` above
whatever the module rail is showing. A new module therefore gets a title without
doing anything, and cannot ship without one.

- **The title is the SERVER's label**, handed down from the rail destination.
  Never a literal in a screen: an organization may rename any module (§8), and a
  hardcoded name goes stale while still looking like a perfectly good title.
- **The subtitle is `UiStrings.maybe('module.<key>.subtitle')`** — `maybe`, not
  `t`, because a server newer than the app sends keys this build has no string
  for, and `module.forecasting.subtitle` printed under a title is worse than no
  subtitle.
- **A pane heading that repeats the tab is deleted, not kept.** That is what pays
  for the header: it replaces a row rather than adding one. Four went — one
  module's own name repeated, Dashboard's own name, HR's "At a glance" under a lit
  *Dashboard* chip, and Scheduling's "Availability" under a tab labelled *Availability*.
- **Settings, Development and Your account get no header.** They are the other
  branches of the shell's switch, they have no server label, and each already
  says what it is.
- **Enforced, not written down.** `module_navigation_test.dart` fails if a module
  opens without a header or if the title stops being the server's word.

**Why the screens could not each be trusted to do it:** they were, and all but one
did not. Exactly one printed the module's name, with a `fontSize: 25` typed into the
screen. Asking sixteen places to remember a header is how you get fifteen that forget.

**A heading style that is not in `theme.dart` renders with no size and no
weight.** `ThemeData` does **not** merge Material's typography in behind that
map, which is the opposite of what everyone assumes: an unlisted slot arrives as
a `TextStyle` carrying only a colour, so the call site draws at whatever the
surrounding text happens to be. Three of the six slots this app uses were never
filled in — 109 call sites, of which `titleSmall` alone was 93. Ninety-three
places that meant "a small heading" were drawing body text. Now defined at
28 / 20 / 18 / 16 / 14, and `theme_conventions_test.dart` fails on any slot the
app asks for that carries no weight of its own. **That test is what found the
third one; the audit behind the gallery had only looked for the two I had
already noticed.**

**Audit, 2026-09-08 — the Leads work against Org/Company/Franchise, Automations
and Roles-Permissions.** Jose's brief on leaving. Measured, not assumed:

- **Scope.** `book()` authorizes `create` on Customer AND Job *scoped to the
  chosen franchise*, so a booking cannot make either somewhere the person cannot
  reach. Placement names a franchise only when the reader can reach it, which is
  why "Placed somewhere you cannot see" exists as a row state. Customers are
  org-shared by design (`CustomersController` says so), so attaching a
  Connecticut job to a customer owned elsewhere is correct, not a leak.
- **Permissions.** Every Leads action is gated — eleven `authorize` calls. There
  is **no side door**: booking requires `leads.edit` *and* `customers.create`
  *and* `jobs.create`, so it cannot be used to create a job somebody lacks the
  permission to create directly.
- **Automations.** Leads declares five triggers; four are emitted, and the fifth
  (`lead.classified`) carries `isRaised: false`, which is the honest mechanism
  rather than a gap. Booking emits `customer.created`, `job.created`,
  `job.scheduled` and `lead.converted` — checked specifically, because a booking
  path that skipped `job.created` would make every automation on new jobs
  silently miss booked ones.

**The one finding, handed to Automations as a prominence fix:** twelve triggers
carry `isRaised: false`, and the only thing saying so on screen is a sentence at
the END of the trigger's description. A rule built on one saves, sits enabled and
never runs, with no error — the exact silent-failure class this app reserves
`errorContainer` and a count for, after five phone lines went to voicemail for
months behind grey secondary text.

> **And the mistake I nearly shipped as a security report.** My first read of
> `book()` was `sed -n '<start>,+22p'` and found one `authorize` call, which
> looked like booking bypassing the Jobs and Customers permissions entirely. The
> other two calls are at relative lines 47 and 50 — outside the window. **A
> windowed read is the worst possible basis for an ABSENCE claim, and a security
> absence is the worst kind to get wrong**, because it is dramatic enough that
> people act on it before it is checked. Read the whole method: compute the end
> with `awk` on the next `function`, never `+N`.

> **A SECOND finding, 2026-09-08, caught before its cut pinned:** website
> enquiries raise no `lead.received`. `app/Support/Web/WebsiteEnquiries.php`
> creates a Lead for `website_chat` and `website_form` and emits **nothing** —
> zero `emit`/`events` in the file — while `LocalServicesLeads.php:137` and
> `ThumbtackLeads.php:73` both emit on create. So an automation on *"Lead
> received"* would fire for three sources and silently skip the fourth, with no
> error and the rule sitting enabled. Partly mitigated and checked rather than
> assumed: messages DO go through `LeadThread`, so `lead.contact_received` fires
> — it is specifically the lead's ARRIVAL that raises nothing. One
> `emitAfterCommit` fixes it. Reported to the desk, Leads and Web Management.
> **Correction from the desk:** it was NOT in the running cut — 0.0.0165 pinned at
> `5c39ce40` before Web's `ab0284de` merged as `12b366f2`, so this rides 0.0.0166,
> which had not pinned. I said "the cut that is running" from the desk's own
> mention of 0.0.0166 without checking which SHA 0.0.0165 was pinned at. The
> finding arrived in time; the urgency I attached to it was a guess.
>
> **The check that found it is the one that came out clean on booking**, and that
> is the point worth keeping: when a NEW path creates an existing kind of thing,
> ask whether it raises the same events the old paths do. Booking passed; the
> website path did not, and it is new code rather than a regression.

**A row with a per-row control and a text-only alternate state has two heights,
and that is what &ldquo;floating&rdquo; means.** Marketing's diagnosis of Web
Management's Hours panel, 2026-09-08, and it generalises: an open day drew two
`TextButton`s, each carrying Material's 48dp minimum tap target; a closed day
drew one line of text at roughly half that. Seven rows alternating between two
heights with nothing ruling them give the eye no grid. The fix is one minimum
height for every row, a hairline between them, and compact controls so the ROW
decides the height rather than Material.

> Two measurements worth keeping: a FIXED height clips — at 375 the times ran
> 78px past the edge, so it is a minimum plus a `Wrap`. And **the floor must
> clear the TALLEST row, not the nominal one**: at 44 the open days measured 45
> and clamped nothing, leaving two heights one pixel apart — invisible to the
> eye and still exactly the reported defect.

**And the boundary that made it Marketing's to fix:** could the change be
described without naming a new thing? *"The rows are the same height and have a
rule between them"* names no new element — that is craft, and it ships. *"There
is now a panel that opens"* names one — that is design. Jose reported it as a
defect and left; routing it would have meant it sat overnight.

**Fix it where it lives, not where it was found.** Leads found that the website
chat endpoint ordered messages by `occurred_at` alone, so two in the same second
tied and the visitor's question rendered *after* the business's answer. They
fixed it in `EnquiriesController` with a UUID-v7 tiebreak and a good comment —
and left `Lead::conversations()` (`app/Models/Lead.php:216`) ordering by
`occurred_at` alone, which is the relation `LeadsController:174` builds the
THREAD from. So the defect survived in the surface where it matters most: a
thread's whole contract is *oldest first*, and a conversation that reads
backwards is the one failure it cannot survive.

> The habit: when a defect is found in a caller, ask what the caller was calling.
> A fix at the call site leaves every other caller broken, and the one that hurts
> is rarely the one that reported it.

**A warning about the system's own capability is DERIVED, never stored.**
Automations' marks for a trigger nothing raises — the picker badge and the
saved-rule note — are computed from the catalogue at render, with no model field
and no migration behind them (verified: zero occurrences in `app/Models` and
`database/migrations`). So the day a sweep raises `item.low`, every saved rule
waiting on it stops saying it will not run and starts running. Nobody has to
remember to clear anything.

> It is the exact inverse of [[release-notes-go-stale-silently]]: a hand-written
> note about behaviour goes stale and manufactures bug reports; a mark computed
> from the thing it describes cannot. **Same class of fact, opposite mechanism,
> opposite outcome** — so when a screen must say something about what the product
> can currently do, derive it from the registry rather than writing it down.

**A second badge must say something the first does not.** Leads' rows carry a
channel badge (Call / Text / Chat / Form) and, since the sources merge, a source
badge (Google / Thumbtack / Chat / Forms). Independent for Google and Thumbtack —
"Call · Google" is two facts. But `LIVECHAT → Chat` beside `website_chat → Chat`
renders **"Chat · Chat"**, and `FORM` beside `website_form` renders "Form · Form".

> Stated so it survives the next source: **draw the channel badge only when it
> would not render the same word as the source badge.** No list of special cases,
> and a fifth single-channel source gets the right answer for free.

This is the third instance of one shape on this screen — the module name printed
by both the shell and the panel header, "At a glance" under a lit *Dashboard*
chip, and now a badge echoing a badge. **A row that gains a mark should be asked
what the marks it already has are saying**, not just where the new one fits.

**A summary line belongs to the question the surface now asks.** The Google
accounts band carried "20 at company level · 2 on franchises" — called at the
time "the only fact worth a permanent row". When the band became the *Connected
sources* roster, that line did not come with it, and should not: placement does
not generalise across sources that have none, and the need behind it is met
better inside the panel, where a placement change already answers "14 leads moved
to Connecticut" on the surface you are looking at when you make it. **A fact
earns a permanent row against the question its surface asks, and that question
can change under it.**

**When two errors are not equally bad, neither one may be the silent default.**
Booking a lead offers `customer_matches` — customers sharing the lead's last ten
digits, which the server itself calls *advisory only*. Attaching to the **wrong
existing** customer puts a stranger's job in somebody's history and **looks
exactly like a correct booking**; creating a **duplicate** splits the history but
shows up as two identical rows. A shared line, a family, a landlord and a tenant
all produce a genuine match between different people. So the panel preselects
NOTHING when a match exists and will not submit until somebody answers, with
"Create a new customer" as a peer of the matches rather than the fallback — and
asks nothing at all when there is no match, because there is nothing to decide.

> The generalisation: **prefer the visible error to the invisible one, but do not
> let that make either automatic.** One extra decision, only where the ambiguity
> is real, taken by the person best placed to resolve it.

**A screen must not imply a reservation it did not make.** `scheduled_for` is a
field on the job — Scheduling has no appointment model, so nothing is reserved,
no slot is consumed and nobody is notified. The control is labelled *When the job
is scheduled for*, never "Book a time": a screen that says Book twice about two
different things will be read as having booked twice.

**A mark needs its own CHANNEL, not its own badge.** When a row already carries
one boolean and gains a second, the answer is rarely a second badge beside the
first — it is a different dimension. Leads' rows carry three: unread (yours),
unreplied (everyone's) and starred (everyone's), in **weight, a dot and an
icon**. Bold-means-unread costs no width in a 340px column, is the one convention
on that screen nobody has to be taught, and survives a filled selected row where
a background tint does not. The rejected options are instructive: a `NEW` pill
put four marks in the column with the two that mattered most looking most alike,
and an unread tint collided with the selected row's own fill.

**No leading-edge accent bar.** Jose, 2026-09-08, with a picture of one: *"stop
with this shits."* The thin vertical rule down the leading edge of a bubble, a
row or a card is out. It was in two of the six conversation-colour layouts and —
the part I nearly shipped — on the **selected list row of the two he approved**,
so "he picked the ones without it" was not true until I looked. Stripped from
every layout in that file including the rejected ones, so nothing there reads
later as permission to bring it back. A selected row is a `primaryContainer`
fill instead: unmistakable at a glance, where a 3px rule is not, and 7.25:1 in
both brightnesses.

> Still shipped in three places design does not own — `email/message_body.dart:174`,
> `suggestions_screen.dart:969`, `staff/suggestions_queue_screen.dart:525`. Whether
> the ruling reaches them is Jose's to say; it is in his queue, not assumed.

**Two panels that summon different people must differ in tense as well as wording.**
HR's certification panel is a *lookahead* with a `days` window defaulting to 60 —
nobody is out of compliance yet. Training's counts passes that have **already lapsed** —
somebody is uncertified right now. A lookahead was offered for symmetry and declined:
making them read alike would hide that one is a warning and the other is a failure in
progress. So HR names its window ("expire in the next 60 days") and Training stays
present-tense ("need to retake something").

**Preview palettes in `docs/previews/` are snapshots, not the source of truth.** All
eight files hard-code `--cyan: #00AEEF`, which `theme.dart` no longer uses for `primary`.
They are **not** being retro-fitted: what Jose approved in each is the *layout*, and
re-touching an owner-approved artefact to chase a token invites the question of whether
the approval still stands. Client work takes colour from `theme.dart` by house rule and
so cannot inherit a stale mockup value. If a mockup's chrome differs from the shipped
screen, the mockup is the stale one.

**Hours has no time picker, and must not gain one.** A picker is a dialog, and this
codebase has no modals. Times are a **masked text field** — validated as real times,
from-before-to, equal refused, masking as it is typed (`8910b88`). Any Hours layout
that assumes a picker affordance is designing against a rule, not a gap.

**A retry belongs where the error was a failed LOAD, not on every error.** The pane
head's retry *reloads*. A form's error is usually a refused **save**, so putting a
reload there offers to recover somebody's work by discarding it — retrying a refused
save is pressing Save again. So: **failed load → retry in the head; refused save →
reported at the footer, retried with Save.** Most panes can do both and must tell them
apart. Found by the Phone session while building Voicemail, against a rule I had
written too broadly.

**Classify a pane by reading it, not by sampling its strings.** Voicemail was recorded
as a *form* in a 13-pane audit and drawn as one. It manages **multiple mailboxes** —
`_boxes`, New mailbox, per-row Edit and Delete, "Back to the mailboxes". Building the
approved drawing would have deleted multi-mailbox support; the module session caught it.
The audit classified from string samples and widget counts, which is a measurement
narrower than the claim built on it — the same failure this file records five other
times, committed by the person recording them.

**A layout that narrows something will expose overflow that was always there.**
Splitting Numbers put its line form in a 320px column for the first time and three
dropdowns plus the place picker overflowed — no `isExpanded`, so "Nobody — go straight
to voicemail" laid out at its natural width. The defect was real the whole time; the
form had simply never *been* narrow, because it always had the whole pane.

> This is the day's measurement theme running backwards. Everywhere else the
> instrument was too narrow for the claim. Here the **condition** was never created,
> so a correct instrument was never pointed at it. When a design narrows a container
> that was previously full-width, expect latent overflow, and treat it as
> **pre-existing rather than caused** — the split did not break those dropdowns, it
> made them ordinary.

**A `Widget` in an `InputDecoration.label` reports its full intrinsic width** and will
overflow a narrow field (measured: 81px at 560). Markers that belong *to* a field —
"also in General", "needs Financials" — go in the help text, which wraps, unless the
design deliberately spends vertical rhythm to put them outside the decoration.

**Grouping a list reorders it, so anything keyed on POSITION breaks silently.**
Adding headings to the Phone rail moved Trust above Tags, and the body switched on the
section's *index* — so one move repointed every section below it. You tap Trust, you get
Tags, and it reads as a rendering bug rather than an off-by-one. Keying on position had
been *safe rather than correct*, for exactly as long as nobody reordered a flat list.
**Any design that regroups or reorders an existing list should say so explicitly**, because
the implementation may be relying on the old order without anybody knowing it.

**Group and order must live in ONE declaration, and headings must be derived from it.**
Two lists cannot be made to agree. A section listed under *Answering* but drawn between
two *Connect* entries is a heading that lies — worse than no headings at all, when the
point of headings is to let somebody stop reading early.

**A group of one still earns its heading when the heading is the finding aid.** Phone's
*Identity* group holds one section. It stays a group: it is the answer to "why are my
calls marked spam", and being buried among housekeeping is how it went unfound.

**A sabotage that does not apply looks exactly like a passing test.** Verifying a check by
breaking the thing it guards only proves something if the break landed. Caught three times
in one day — twice from CRLF, where a pattern written with `
` silently fails to match a
file with `

`, the replace does nothing, and the run passes green. **Confirming the
sabotage applied is part of doing one.**

**Do not collapse the reasons an incapacity exists — the reason decides who fixes it.**
Ring groups was drawn with two unavailable states (signed out, Do not disturb) and the
data has **three**: *never signed in to Phone* is a separate fact, and it is usually
somebody who **does not know they were put in a ring group at all**. Those need three
different actions — go and tell them, wait, or respect a deliberate choice — so
flattening them into "unavailable" throws away the only part that tells you what to do.
Found by the Phone session while building `10ff9d0`, against a drawing of mine that
implied two.

**When the screen needs a fact the server computes but discards, grow the endpoint —
never recompute it in the client.** Reachability lived inside `DialPlan::batches()` and
was knowable only mid-call; the pane got member ids and nothing else. `reachableUserIds()`
now exists and `batches()` calls it, so the screen and the dialler share one rule. The
same shape as `describe()` keeping one boolean out of a whole trace. **A client-side
reimplementation of a server rule is a second copy that will drift**, and the drift is
silent because both sides look right in isolation.

> The test for that pair has to run against a **mixed** group. All-reachable or
> all-unreachable passes against two implementations that disagree — which is a
> condition never created rather than an instrument too narrow, the same trap as the
> `isExpanded` overflow.

**A missing measurement is not a zero measurement.** Third instance, so it is a rule
rather than three fixes: attestation shows *not captured* rather than a 0% rate;
Scheduling says *configured capacity* rather than *remaining*; Cost marks today
**not yet counted** rather than £0 because the import runs overnight. Each time, a
plausible zero was available and would have been read as a fact. **If the number is
absent, say absent — a zero is an assertion.**

**Which way an absent FIELD should default depends on which direction the claim is
serious.** Phone's decision, and it does not generalise — deliberately:

> `plays` missing → **assume it plays**. "No caller ever hears this menu" is an
> accusation; inventing it from an absent field paints every healthy menu red against
> an older server.
>
> `reachable` missing → **assume NOT reachable**. "This group will answer" is a
> reassurance; inventing it means a call goes nowhere while the screen says it is fine.

**Default toward the claim you would rather be wrong about.** Same field shape,
opposite safe default, because one is an accusation and the other is a promise.

**Every gallery is 3 desktop options and 3 mobile options — and the mobile ones are
DESIGNED FOR MOBILE, not the desktop layout narrowed.** Jose's instruction, and it
corrects a habit: every "at 375px" drawn before this was a desktop layout squeezed.
Choosing a desktop layout does **not** choose the phone one; they can be completely
different designs, because they are different users in different situations.

> *Your account* is the worked example. Desktop answers **"show me everything about my
> account"** — which is why a two-column layout with the facts always visible is right
> there. A phone user came to change **one thing** — a photo, a number, two-factor after
> a prompt — and a long form answers that badly: you scroll past nine things you did not
> come for to reach the one you did. So the phone version is rows that each open one
> screen, and the read-only facts drop to the bottom. On desktop they cost nothing
> because they sit in space that was empty anyway; on a phone every one of them is
> between you and the thing you opened the app to do.

**A phone-only constraint that has no desktop equivalent: the keyboard covers half the
screen.** A single Save at the end of a long form sits *behind the keyboard exactly
while you type*, and "two changes not saved" is invisible at the moment it matters
most. One-job-per-screen avoids it entirely by putting Save in the bar; a single
scrolling form has to pin its footer above the keyboard, which is possible and is one
more thing to get right.

**A session name is an ADDRESS. A session id is an IDENTITY. They are not the same
thing, and a recipient cannot derive one from the other.** `SendMessage` shows a
rotating per-hour handle — the deploy desk was `ezbizi-a1`, then `ezbizi-28`, then
`ezbizi-5b` in one day. The session-management tool shows a stable title,
"EzBizi - Deployment". **Same session, two identities, and nothing in either message
says so.**

> This produced a false alarm worth learning from: two messages arrived minutes apart
> asking identical end-of-day questions, one from each transport, and I concluded there
> were two deploy desks about to collide on a release. There was one. The *observation*
> was right — two addresses, no way to tell — and only the inference was wrong.
>
> It is worse than a stale name in one respect: **a stale name at least resolves to one
> thing.** Two live addresses for one session resolve to two, and the recipient has no
> way to collapse them.

**So: route by the current name, but establish identity by asking.** "Which module do
you hold" and "what is your session id" are the only reliable questions — and when
attribution matters at all, cite a commit SHA, which is the one handle that outlives
every address and every title.

**Never express a state by fading the element that carries text.** Transparency attacks
legibility directly, and it always bites hardest in the state where legibility matters
most — dragging, disabled, loading, pending. Express it with elevation, an outline, a
border, a cursor, or a ghost placeholder; **the ghost carries no text, so fading *it*
costs nothing.** That last clause is the practical half: the rule says where the
transparency is allowed to go, not merely where it is forbidden.

Found in a Gantt bar: `scheme.primary.withValues(alpha: dragging ? 0.5 : 1)` wrapping
`Text(title, color: scheme.onPrimary)`. At 50% over white, #00AEEF composites to
about #7FD6F7, and white on that is **≈1.6:1** — the label all but vanishes at the exact
moment the user needs to know which row they are holding. Note this is **independent of
the token defect below**: `_cyanDeep` at 5.65:1, halved by the same alpha, lands near
2.6:1 and still fails. Fixing the token would have made everyone believe this was handled.

**Horizontal scroll is legitimate where the DATA is wide, and illegitimate for CHROME.**
A table and a Gantt should scroll sideways — horizontal is the data's own axis. A tab
strip or a view selector has no natural width; it is only as wide as someone's label
list happens to be, which is not a reason to make a user drag. Chrome that overflows
at 375px **wraps**. One screen had two stacked chrome bars — a presentation picker
above a saved-view picker — both clipping flush at the right edge with no affordance.

Prefer `Wrap` over any scroll cue. A fade needs a gradient stop — a colour literal, and
a second one for dark mode, against the `theme.dart` rule. An arrow is a control that
is not one: tap it and either nothing happens or it moves by an amount nobody chose. A
peek is the best cue and still explains a scroll that need not exist.

**An isolation widget test cannot see the chrome around what it mounts.** One suite
asserted zero horizontal scrollers at 375px for a Kanban body widget — true, passing,
and blind to two overflowing bars the screen mounts above it. Assert layout floors on
the **screen**, not the widget.

> **"A true measurement of the wrong thing."** One session's name for the failure
> shape behind four separate escapes in one plan: a suite proving a recurrence's first
> hop and silent on the series; a timezone test that cannot fail on half the machines
> that run it; a pixel diff perfect at *did only the intended region change* and
> structurally incapable of *does it look right*; an isolation assertion blind to the
> chrome above it. Every one passed. Every one was measuring correctly. Ask what a
> green test is not looking at.

**Goldens and briefs live on branch worktrees, not `main`.** `git worktree list` —
Fleet, Training, Web Management and others each have their own tree. Reviewing
a module's golden PNGs from `main` reviews the wrong file, silently, because the paths
are identical. Confirm the tree before reporting pixel findings.

**On the public web paths, the key IS the boundary — so "regenerate" is a safety
control, not a convenience.** Web Management audited this 2026-09-08 (`f889c6fe`, seven
cases, mutation-checked) and I verified the mechanism rather than taking the summary:
six lookups across four paths — plugin token, snippet public key, widget public key, and
the visitor's per-conversation token — run before any tenant exists, so they call
`TenantContext::runWithoutTenant()` and the row itself names the organization.

The design is sound and worth knowing precisely, because it is better than "the scope is
dropped": `TenantScope::apply` **throws** when no tenant is bound rather than quietly
returning everything, and the opt-out is one named, greppable call. Unscoped reads cannot
happen by accident here; they have to be written on purpose.

> What that constrains for design: on those paths the unguessability of the key is the
> whole of the isolation. So **never shorten a key for display and offer copy from the
> shortened form**, never reuse one key across sites for tidiness, and never bury reissue
> — a key that leaks is the entire incident, and regeneration is the only remedy. Web's
> browser key already reissues inline and never in a dialog, which is the right shape;
> the rule is written down so the next screen does not have to rediscover why.

**Refinement, from Web Management the same evening — and the sharper rule.** Not all keys
are one animal. `pk_` is public by design and printed in page source; `ezb_` is a secret
that reads a company's unpublished details. One leaking is an annoyance, the other is an
incident, so **two credentials of different consequence must never be presented alike.**

They had already built this, and their reason is better than the rule I first wrote:

> *"The public key shipped in the API and had nowhere to appear, so the only credential
> this screen showed was the SECRET token. Asked for the browser key, the honest answer
> from anybody looking at this panel was to hand over the `ezb_` one, because it was the
> only key on screen. **That is not a user error; a screen that shows one credential and
> needs another is the error.**"*

The fix was to show both together, named for what each is, with the distinction stated in
place — `seo_tab.dart:1436`. **Generalises past credentials:** whenever a screen shows one
member of a set whose members look interchangeable, it is not neutral about the others —
it nominates the one it shows. Distinguishing prefixes (`pk_` / `ezb_`) do the same job
for a value pasted into a support ticket, but a prefix cannot fix a screen that offers
only one of them.

**An advisory must not wear the failure component.** Web Management, 2026-09-08, asking
that this be made a rule after the widget contrast work: *"dressing an advisory as a
failure teaches people to ignore the component that also carries real failures."* The
contrast note on `widgets_tab.dart` deliberately does not use `ErrorNote`, which
documents itself as *"a failure the user needs to read"* — nothing failed there and
nothing is blocked, and the customer may have chosen the low-contrast pair on purpose.

> The cost is not on the advisory, it is on the component: every non-failure wearing the
> red error treatment is a lesson that red can be ignored, and it is collected by the
> screens where red does mean stop. **Ask what the user is meant to DO** — fix it now, or
> know it. The second one is prose in the surrounding style, not a bordered alarm.

**Delegating half a subject to a shared helper makes the un-delegated half invisible.**
Web Management, 2026-09-08, offering their own miss as a category: *"the code looks
event-aware because some of it is."* Website enquiries wired their conversation half
through `LeadThread` and got `lead.contact_received` for free — which is precisely
why nobody went looking for the arrival event, and `lead.received` was never raised for
a website enquiry at all. The half that came free from the helper made the half that
did not look handled.

> As an audit category: **a subject that is half-delegated reads as fully handled.**
> When a module gets some of its behaviour from a shared helper, list what the helper
> supplies and what it does not, and audit only the second list. Grepping for the
> module's own event calls finds the delegated half and stops.

**A colour token cannot be exported past the theme that gave it meaning.** Web
Management asked, 2026-09-08, whether widget defaults on customers' own websites should
inherit these palette rules rather than drift. They should not inherit the *values*, and
the reason is measurable: `primary` inverts between brightnesses — `#00708f` on white
is 5.65:1 and `#00aeef` on near-black is 7.94:1, both correct here. Export the dark one
onto a customer's white page and it is **2.53:1**, under even the 3:1 large-text floor.
A widget config has no brightness to resolve against, so whichever value travels,
travels without the pairing that made it correct.

> The design reason outranks the arithmetic, though: **the widget is the customer's
> brand surface, not ours.** These tokens exist to make EzBizi look like EzBizi; putting
> them on A-1 Chimney's website makes A-1 Chimney's website look like EzBizi. Their
> default `#0b5cad` (6.67:1 on white) is right *because* it is not our brand colour —
> it reads as "a form", not as any particular company. We hold `logo_path` on
> Organization and no brand colour anywhere, so the customer types their own; the
> default's job is to be neutral and legible until they do.
>
> What does travel is the **rule, not the token**: a foreground and its background are
> chosen together and checked together. Queue item 5 is that rule applied — both colour
> pairs on that screen were shipping unchecked, and now show the ratio a visitor gets.

---

## Known gaps in the four cross-cutting files

**`dashboard_panels.dart:73-75` — a new module can silently get the wrong icon, and
the gate stays green.** The lookup ends
`byModule[key] ?? byModule[key.split('.').first] ?? Icons.insights_outlined`, over
twelve module entries. `GlanceRegistryTest` asserts sidebar modules against glance
keys as a **sorted-set equality**, so a module declared *without a glance* turns the
gate red — loud, and it has already caught Training and Scheduling. **Nothing asserts
the icon map.** A module that registers its glance correctly and omits an icon row
passes, and renders a generic chart nobody chose, permanently. The two failure modes
are asymmetric and the silent one is the one that reaches a customer. Likely fix:
extend that same sorted-set equality to cover the icon map. *Raise with the deploy
desk before landing — it is their test as much as this file.*

Credit and framing to the deploy desk, which corrected its own earlier account of
this file rather than letting it stand: **a conclusion that survives only because of
a fallback is true today and quietly fragile.**

**`theme.dart` — `colorScheme.primary` fails WCAG AA as text, app-wide.**

    ColorScheme.fromSeed(seedColor: _cyan, primary: _cyan, surface: Colors.white)

`primary` is explicitly overridden to raw `#00AEEF`, overriding the darker tone Material
would otherwise derive from the seed, and light-mode `surface` is explicitly white.
So `primary`-on-`surface` is **#00AEEF on #FFFFFF ≈ 2.5:1**. AA needs **4.5:1** for
normal text and **3:1** for large text and non-text UI — it fails both. `onPrimary:
Colors.white` on a cyan fill is the same 2.5:1 in the other direction.

Modules using `colorScheme.primary` for text are doing exactly what the house rule
asks; **the token is wrong, not the usage.** The palette already carries the fix:
`_cyanDeep #00708F` on white is **≈5.65:1**, comfortably AA, and is already wired as
light-mode `secondary`. Likely answer: cyan keeps large fills and brand moments,
`_cyanDeep` carries text and small elements on light surfaces. Dark mode needs its own
check — this measurement is light mode only.

*Touches every module, so it is a `theme.dart` item for Jose, never a change slipped
into a module's plan. Accessibility is on the never-simplify-away list.*

**Colour literals are house-ruled but ungated.** Counted 2026-08-31: **78 occurrences
across 22 files** outside `theme.dart`. The raw number overstates it — 9 are
`Colors.transparent`, 2 are `signature_field.dart`, the sanctioned exception. That
leaves ~67 candidates, **29 of them raw `Color(0x…)`**, not individually inspected and
so not yet called violations. The actionable fact is concentration: **four files hold
51 of the 78** — `widgets/auth_preview.dart` 18, `screens/email/message_list.dart` 13,
`widgets/auth_shell.dart` 11, `screens/hr/contract_document_page.dart` 9. Two are the
login surface. Bounded cleanup, not a sprawl.

**`module_screens.dart` is the intersection of declared modules and client screens.**
Declaring a module cannot put a tab in front of an older binary — the frozen 0.0.0112
build has no `training` key at all. Reassuring direction: a design cannot reach a
client that has no screen for it.

## Inherited constraints (not design's to change)

From `StartUp.md` §6 and the departed rules desk. `docs/ACTIVE-CONSTRAINTS.md` is the
live one — read it rather than this summary.

> **THE APP STORE FREEZE IS OVER** (2026-08-31, confirmed by Jose via the deploy desk).
> `ACTIVE-CONSTRAINTS` §1 — no change to an existing response shape while a frozen
> `.ipa` ran against production — is **gone**. Anything a design deferred for that
> reason is unblocked, and "additive only" is no longer a constraint on a proposal.
> §2 (minimum client version) and §3 (SES sandbox) outlive it.
>
> Designs drawn under it and now free to ask for more: widening
> `describe()`'s trace instead of keeping one boolean (Phone · General), the
> distinct `skipped` bool, Training's `summary` roll-up, and the Numbers
> recommendation that moves line editing into General.

1. **No modals. Ever.** A new screen or a right-side panel, including
   confirm-before-destructive and including text entry. `customer_detail_panel.dart`
   is the worked example of the panel pattern; do not invent a second dialect.
2. **Colours only from `theme.dart`.** One deliberate exception exists and is the
   shape of a good one: `signature_field.dart` names black ink and white paper
   because those are the pixels of a printed document.
3. **375px must work.** Touch and pinch first-class. A floor, not a target.
4. **If the answer would be 403, do not draw an enabled button** — including per-row.
   The row is disabled carrying the **server's** sentence from
   `app/Support/Access/Refusal.php`, with a server field saying so. A client-side
   copy of the rule will drift.
5. `ui_strings.dart` has two migrations behind it, so some strings are tenant data.
   A copy change is not a one-line edit.

---

## Queue

**Approved designs are pinned to a SHA, not to "on main".** Main moves every two or
three minutes on a busy afternoon, and a design approved *as drawn* is a claim about a
specific drawing. `git show <sha>:docs/previews/<file>` is what was approved; the file
on main may have moved on.

> **🔨 being built · ⏳ approved, an owner holds it · 📬 approved, routed to a desk, no owner yet · ⚠️ unowned and unrouted · 📐 drawn and numbered, waiting on an answer · ✅ never used here.** An approved
> design with nobody building it *looks* like progress on a queue and is not — it is
> a drawing. The deploy desk's line, and it applies to this file before it applies to
> anyone else's: a ticked row that means "waiting for a session that does not exist"
> is the same failure this doc warns about twice elsewhere — **the marker stays
> plausible and the meaning moves.** A row leaves this table when it ships, not when
> it is approved.
>
> **📐 was added for Suggestions and is the state this table had no word for:
> drawn, numbered, in front of Jose, and nothing chosen.** It is not ⚠️ — nothing is
> unowned; and it is not 📬 — there is nothing to route, because there is nothing to
> build yet. It is the one shape this file says *does* belong on the owner's desk:
> genuinely blocked on a decision rather than merely lacking a body. A 📐 row becomes
> 🔨 the moment a number comes back, and **no number is recorded in it before then** —
> writing a provisional one is the marker-means-two-things failure again.
>
> **And an unowned design is ROUTED, not escalated.** A finished drawing with no
> module session goes to the deploy desk, which outlives individual sessions. It does
> not go back to Jose as a staffing request.
>
> The failure underneath, named by the deploy desk after we both made it three times:
> **an item genuinely blocked on a decision and an item merely lacking an owner look
> identical in a list.** The first belongs on the owner's desk. The second only gets
> there because nobody wants to be the one holding it. Same shape as a tick that means
> two things — and it applies to what design puts in front of Jose, not only to this
> table: **if the answer is "find someone", it is not his to answer.**

| # | Item | State |
|---|---|---|
| ✅ | **Phone · Caller Identity & Trust** | **SHIPPED `cb27c68`.** Layout 1, built from the `d66bcef` pin. Merged with the existing Trust section as *Trust & caller identity*, absorbing Number quality, rail 14 → 13. This row read "being built, held by `ezbizi-0b`" for hours after it shipped, because the holder named in it stopped existing at a restart and a 🔨 cannot tell that from work in progress — the failure this table warns about, in the table. |
| 🔨 | **Scheduling · Availability + Settings** | **Layout 7 approved, being built** (Overview + area rail — Jose's own combination of 1 and 2); Availability is **layout 4**. `docs/previews/scheduling-layouts.html` @ `560aed5`. Held by the Scheduling session. |
| 📬 | **Fleet · vehicle Identity card** | **Layout 2 approved, handed to the deploy desk to route.** `docs/previews/fleet-identity-layouts.html` @ `560aed5`. The Fleet session that briefed it ended before it was drawn. **Not urgent** — nothing in Fleet is broken; the card works and the complaint was a long scroll at 375px. Fold it into the next Fleet work rather than staffing for it. |
| ⏳ AWAITING JOSE | **Leads · connected accounts** | Six layouts in `docs/previews/leads-accounts-layouts.html` — 3 desktop, 3 mobile. Brief from the Google API session (`local_8111908f`), server done at `c55b3c33`. **22 Google Ads accounts, one shown**: `leads_screen.dart:179` `_connectionFor` returns the first provider match. **Two payload gaps found by reading it and raised with them:** `connectionState()` carries no `leads_franchise_id`, so the placement column cannot render on load; and nothing links a lead to the account that produced it, so no per-account count exists client-side or server-side. The count is not a decoration — it is what separates "2 accounts that matter from 20 quiet ones" from twenty-two identical rows, and layouts marked `needs the count` degrade honestly without it. Design builds it once picked. |
| ✅ **REVERSED BY JOSE, 2026-09-09** | **Leads · four sources** | Desktop **3** + mobile **6**, the hybrid — `docs/previews/leads-sources-layouts.html`. He asked for *"a new sub tab in Leads"*; I shipped **1 / 4** (one list, source as a filter) in his absence and flagged it as one word to reverse. The word came: **3 and 6**, tabs with All in front. Built `4a0e0f2` — the source moved out of the filter row into a strip of its own, and nothing else changed: same row, same badges, same sources band, still ONE list. **The drawing was wrong about one thing and the build is not:** mobile option 6 had Thumbtack pushed off the end, because the HTML mock could not scroll. `TabBarLike` does, so every source has a tab at 352px. Losing a live source to a mock's limitation would have been a real gap, not a compromise he chose. **Two Alls now, deliberately** — the strip's tab and the filter row's chip, both meaning the unfiltered list; clearing the filters moves the strip too, or a tab stays lit describing something no longer on screen. Tested, and the test was sabotaged to prove it fails without it. |
| ✅ APPROVED, **built by Leads** | **Leads · book a job from a lead** | Desktop **1** + mobile **4** — `docs/previews/leads-booking-layouts.html` @ `d1c69cb5`. Jose, 2026-09-08: *"as you recommend for the layouts"*. **Nothing is preselected when `customer_matches` is non-empty**, and Book stays disabled until answered — see the rule below. No match → straight to a new customer, no question. |
| ✅ APPROVED, **built by Leads** | **Leads · read, star, note** | Desktop **1** + mobile **4** — `docs/previews/leads-triage-layouts.html` @ `18012c74`. Jose, 2026-09-08: *"2 - as u recommend"*. **Unread is WEIGHT, not a second dot** — the dot is unreplied and a leading-edge bar is banned, so the three marks live in three channels: weight (unread, yours), dot (unreplied, everyone's), star (everyone's). Filters narrow TOGETHER. On the same delegation the note gets an **author and an edited-at**, asked of Leads: a shared free-text field that cannot say who or when is one people stop trusting, and silent last-write-wins is the worse half. |
| ✅ APPROVED, **built by Leads** | **Leads · conversation colour** | Desktop **3** + mobile **6** — `docs/previews/leads-conversation-colour-layouts.html` @ the commit below. Jose, 2026-09-08: *"option 3 for desktop, 6 for mobile"* — the saturated direction, ours filled `primary` with `onPrimary`. **No accent bar anywhere**, per the same message. `primary` inverts between brightnesses (#00708f/white light, #00aeef/near-black dark), so the build must be checked in both. |
| ✅ APPROVED, **built by Leads** | **Leads · conversations** | Desktop **1** + mobile **4** — `docs/previews/leads-conversations-layouts.html` @ `6c3f6d46`. Jose, 2026-09-08: *"let leads build it, yes approve 1 and 4"*. **This is an explicit exception, not a change to the rule.** "An approval is not a delivery" still stands and design still builds what design draws unless Jose says otherwise for a named piece of work — that rule exists because three approvals rotted unbuilt, and one directed handover does not undo the reason. Handover sent to `local_8111908f-…` with the full constraint list. |
| ✅ SHIPPED `2819822` | **Your account · desktop + phone** | **Desktop layout 3** — `docs/previews/account-profile-layouts.html`; **phone layout 2** — `docs/previews/account-profile-phone-layouts.html` @ `b0fa539`. The two are **deliberately different designs**, not one narrowed: desktop answers *"show me everything about my account"* (form left, assigned facts in a always-visible right column); phone answers *"what do you want to change?"* (identity header, then rows that each open one screen and each show their current value). **Carry into the build:** the date of birth is a **masked field, never a picker** — a picker is a dialog, same rule as Hours; gender is the chips **only**, not chips plus a text box; and **"You can act on" must be DISTINCT** — it currently lists Connecticut four times and DMV twice, fourteen entries for ten places. No module owns `main.dart`, so design builds it. |
| 🔨 | **Phone · Call desk proportions** | **Layout 2 approved** — `docs/previews/phone-call-desk-proportions.html` @ `bb289f9`. **25 · 25 · 50** (Team / Dial / Customer) as `flex: 1 / 1 / 2`, **with floors** ~220 / ~260 / ~340. Today is `SizedBox(220) · Expanded · `SizedBox(280)` = 19/57/24, so the panes holding the most content are fixed at their smallest and the pad takes the remainder. The floors exist because **a keypad has a minimum and a roster does not** — bare 25% gives the pad 166px at a 950 window, above the stacking breakpoint. |
| 🔨 | **App sidebar · scrolling** | **Layout 1 approved** — `docs/previews/app-sidebar-scroll-layouts.html` @ `bb289f9`. **Destinations scroll, the Settings/Development tail stays pinned.** `_rail()` returns a Flutter `NavigationRail`, which lays destinations in a Column and **cannot scroll**; inside an `Expanded` it overflows and draws over the tail. Height-triggered, threshold ~780px of sidebar — fine at 1080, broken at 800. Items underneath are **unreachable rather than hidden**. No module owns `main.dart`; design is building it. |
| 🔨 | **Phone · Connection** | **Layout 1 approved** — `docs/previews/phone-connection-layouts.html` @ `fc5b838`. Draws the org→company inheritance as a tree. Replaces **six prose variants** of one rule, each correct, each different depending on where you stood. No footer (nothing to save); retry in the head (its error is a failed load). |
| 🔨 | **Phone · Hours** | **Layout 3 approved** — `docs/previews/phone-hours-layouts.html` @ `f545c31`. Today’s shape kept: opening a set **replaces the pane**, with Back. **Deliberate, not an oversight** — Numbers is a split and Hours is not, because seven rows of masked time fields want full width. *Say so in the code*, or somebody will later “fix” Hours to match Numbers. Holidays get their own bordered block above the week; a day with no times is an absent row, never empty fields. **No time picker** (`8910b88`). |
| 🔨 | **Phone · Ring groups** | **Layout 1 approved** (split pane). `docs/previews/phone-ring-groups-layouts.html` @ `f545c31`. **The point is not the split:** reachability is a COLUMN of the member list, drawn from `DialPlan::batches()` — a group of four can ring nobody and look fine today. Tally is timestamped ("as of a few seconds ago"), never implied live. Keep the 60-second timeout explanation inline. Expect latent overflow in the narrowed detail column — pre-existing, not caused. |
| ✅ | **Phone · Settings rail** | **SHIPPED `0d8e52c`.** Layout 1 (grouped rail) — `docs/previews/phone-settings-rail-layouts.html` @ `6389714`. Queued behind Trust with the Phone session. |
| ✅ | **Suggestions · the author's tab** | **2 — JOSE'S CHOICE, 2026-09-04: "i like 2 for both".** Overrules the provisional 1 below, which had already shipped, so this is a change to a live screen and not a branch edit. **Layout 2 is `what happened to what you sent`:** a rail split into Waiting on EzBizi / Answered with a Write-a-suggestion button above it, and the selected one in full in a detail pane. My argument for 1 was that 2 is built for a volume nobody will have — that argument lost, and it was only ever a guess about how people would use a tab nobody had used yet. Recorded because the reasoning below is now history and must not read as current. *Previously:* **1 chosen — PROVISIONALLY, by Claude under delegation, not by Jose.** `docs/previews/suggestions-1.html`, `-2.html`, `-3.html` — 1 compose-first, 2 what-happened-to-what-you-sent, 3 correspondence. Jose left for the day at the gate and said "pick your recommended layouts, and we'll change them tomorrow if I don't like any", so **1** was chosen on these grounds: 2's master-detail rail and 3's stream are both built for a volume nobody will have (0–5 suggestions, ever), and 3's correspondence metaphor promises a reply the backend does not support — one note per suggestion, and the author cannot answer it. 1 matches the data, and its empty state *is* the compose box, which is what nearly every first visit will be. **This is a standing invitation to overrule, not a settled decision.** Backend `f13dd81..f845c4e`. |
| ✅ | **Suggestions · the Development queue** | **2 — JOSE'S CHOICE, 2026-09-04: "i like 2 for both".** Confirms what already shipped, so **no code changes for this row** — it is recorded so nobody later reads a provisional marker and rebuilds a screen that is already the approved one. *Previously:* **2 chosen — PROVISIONALLY, by Claude under delegation, not by Jose.** `docs/previews/suggestions-queue-1.html`, `-2.html`, `-3.html` — 1 worklist table, 2 list-beside-the-answer, 3 everything-in-full. **2** on these grounds: the act this screen exists for is to read a suggestion in full and write a sentence the author reads verbatim, which is why the server makes a note mandatory to deny. 1 truncates into table cells the prose you have to judge; 3 keeps the full text but repeats the answer box on all 200 cards. 2 holds the suggestion in the pane while the note is written in the footer. Same standing invitation to overrule. |
| 4 | **Web Management · per-page value overrides** | Briefed. `license` ruled **conditional**. Not started. |

**Phone, shipped 2026-08-31 and not re-listed above:** Voicemail on the shared pane skeleton (`3223015`), Numbers split pane (`b25c3db`), staff messaging (`ed3b35f`), extensions (`8b82ed2`), and the rail-is-a-switch guard (`753556b`). Still approved and NOT built: **Connection**, **Hours**, **Ring groups** — the three 🔨 rows above, held by the Phone session (`ezbizi-0b` → this one).

**Also approved and shipped 2026-08-31:** the `theme.dart` contrast fix and the
colour-literal ratchet, both in `d0ce75a`.

### 1 · Phone · Caller Identity & Trust — two open rulings

- The rail holds **both "Trust" and "Number quality"**, and both answer "is something
  wrong with my number". Recommendation: this screen absorbs the existing Trust
  section (port-in / carrier review) as **Trust & caller identity**, taking the rail
  14 → 13. Drawn merged. *The overlap was inferred from a section list, not read from
  code — verify before building.*
- **The screen is empty on day one and stays partly empty forever.** EzBizi discards
  StirVerstat on inbound calls, so no attestation history exists anywhere and none
  will until capture is built. Layout 4 is that day-one screen.
- **Attestation percentages cannot share a denominator.** The reference shows 61 calls,
  30 graded A, 31 not captured — "0.0% degraded" is over the 30, not the 61. A naive
  bar reads half-alarming. The mockup hatches the gap and says in words that it is an
  absence, not a bad grade. **Confirm the real payload computes it the same way.**
- No Twilio API reports whether a number currently shows as "Scam Likely". The
  observation log is a human record and must read as deliberate, not as a failed sync.
  The Trust Hub Business Profile is **console-only** — no API creates one — and Voice
  Integrity needs it *approved*, not merely created, which is why the two registrations
  can differ for one reason while looking independent.

### Scheduling · Availability + Settings

Availability means **dispatch capacity** — jobs takeable per slot, per weekday, per
service area. Areas hold zip codes; one zip belongs to exactly one area, enforced.
Slots and capacity are **per area**, not franchise-wide (Jose ruled directly).

Jose's two shaping answers: area count **"varies a lot by franchise"** — two and thirty
are both real, so the area list is searchable and never assumes it fits; and slots are
**"the same everywhere in practice"**, so the grid is regular and divergence is the
exception. Together those make *configure one pattern, apply to every area* the primary
act, not a convenience.

**Three constraints that are easy to lose:**

- **Closing a day must DELETE slot rows, never write zeros.** Storage distinguishes "we
  do not work Sundays" (no rows) from "we work Sundays and are full" (rows at zero). If
  a close affordance ever zeroes instead, the two collapse into one appearance and
  nothing errors. The layouts rely on this: a closed day is an **absent column**, not a
  column of zeros — which is also what makes the week fit at 375px.
- **No per-date overrides exist and none are designed** — capacity is per weekday and
  recurring. But the cell is *shaped* to carry an exception marker nothing sets, because
  "closed this Thursday for the holiday" is close enough to certain that designing it
  out would be a choice. No field, no endpoint, no creation UI.
- **Nothing burns a slot yet** — Scheduled Jobs does not exist, so every cell reads full
  capacity forever. **Ruled by Jose: the cell is called "configured capacity", not
  "remaining".** That is what the number is; the lie was the noun, not the number. It
  becomes "remaining" when Jobs lands, and the label moves with the meaning.

**No pattern table — settled, and the reasoning matters more than the verdict.** A stored
pattern is not one table, it is a template system: it needs a precedence rule (does
editing it rewrite existing areas or only new ones?), an override model (does an area's
exception survive a pattern edit?), and a migration for areas that already diverge. Three
decisions nobody asked for, each a place for a fact to quietly change. The pattern is
derivable — comparing slot sets is cheap — so "apply a pattern" is just a write: delete
an area's rows, write the new set. No pattern object, nothing to keep in sync.

**And no intent column either, on a narrower argument.** Comparison can tell that an area
*differs*, not whether it differs *on purpose* — so a bulk apply could silently flatten a
deliberate exception. But ask what actually writes a drift: with one write path and one
human, **difference is intent by construction.** The single exception is a bulk apply
that reaches some areas and not others. So close that path rather than recording intent
around it:

1. **All or none.** A partial apply is the only thing that manufactures a difference
   nobody chose; it should not be a reachable state.
2. **Name what will be overwritten, before it is** — the actual areas and values, not a
   generic warning. The user re-states intent when it is at risk.

> **The trigger for revisiting:** once a franchise holds more than a handful of deliberate
> exceptions, that confirmation becomes a wall people click through — which is the silent
> flatten again. Then a nullable "deliberately its own shape" column on `service_areas`
> earns its place, with real data about which areas rather than a guess. Zero franchises
> have used this once.
>
> Note this lands opposite to the per-date exception cell, deliberately: shaping a cell
> costs nothing now and a re-cut later, so it was done; a column costs a migration either
> way, so it waits for a reason.

### 2 · Fleet · vehicle Identity card

Reported by the Fleet session against the 24 real A-1 vehicles; **measured by them,
not re-verified here.** Card edits in place — every field a live control, enabled on
`fleet_management.edit`, one Save, no separate view mode. Read-only layouts are
therefore not on the table.

Fill rates, 21 fields of 24 vehicles: **always** year, make, model, plate_state, vin,
body_type, fuel_type, status · **22** colour · **16** odometer_miles,
odometer_updated_at · **9** plate · **3–4** toll_device_number, toll_plan,
registration_expires_on · **0** trim, next_service_on, next_service_miles,
inspection_expires_on, toll_flat_rate_cents, notes.

**The finding, and it is not a layout problem:** `plate_state` is 24/24 while `plate`
is 9/24, because the import correctly nulled eleven typed absences ("NA", "(NEW)").
Fifteen vehicles render "Plate state: CT" beside an empty Plate — two fields, one
fact, and the always-present half is the useless half. Candidate: one control, a plate
field with a state selector attached.

`toll_flat_rate_cents` is in the API and DB with **no control at all**. If surfaced it
goes behind `fleet_management.financials` — the server already strips it from the
payload without that permission, so a control drawn unconditionally is an empty box.

### 4 · Web Management · per-page value overrides

No UI exists; data and API already support it. **Zero overrides in production** — the
module's primary answer to "this page needs a different number" is a different *site*
(a subdomain). Per-page is the escape hatch. Design for three pages with one override
each; make forty degrade rather than break.

**Override is per KEY, not per page** — a page overriding `phone` still renders the
site's address, email, hours and licence. Both consumers resolve identically.

**And `ValuesController` deletes on empty rather than storing `""`.** So "cleared" and
"inherited" are the same stored state. A design showing them as different states would
be drawing a state the backend cannot hold; a design showing an empty box for both
would let someone clear a field and get the site's phone number instead. **Settled:**
two states, and clearing visibly reverts to the inherited value shown in place —
cleared and inherited look identical *because they are* identical.

**Field requirement tiers — shipped `ec0e114`, Web Management's work.** A blank `phone`
and a blank `license` are opposite facts stored identically, so no single empty
treatment can be correct for both. Now a per-key `requirement` beside `label` and
`hint`, asserted by the catalogue, never set by the user, present on all three
endpoints:

| tier | keys | empty treatment |
|---|---|---|
| **required** | `company_name` `phone` `address` | prompted at the field, inline and unmissable |
| **conditional** | `email` `hours` `license` | **the field says nothing.** Appears once by name in a single collapsed line: *"Not published on this site: hours, licence."* |
| **optional** | `service_area` `booking_url` | nothing, anywhere |

The middle tier earns its place only if a design actually draws that summary line —
otherwise collapse it to two. Never a per-field nag: an appointment-only business
would meet it every visit forever, which is how people learn to ignore a screen.

**The split is a guess and its docblock says so.** No customer has filled more than
two of the eight keys, so every assignment is reasoning about what a trade business
publishes, not usage data. A test pins the required three deliberately — moving a key
between tiers should be a decision, not a side effect of editing a list.

> **Open for Jose — a product judgement, not a design one.** `license` is the least
> certain. A New Jersey chimney contractor must display a licence number, so for A-1
> a blank licence is arguably unfinished; but the catalogue serves every customer and
> most trades in most states are not licensed. Conditional risks mentioning it to the
> unlicensed majority; optional means A-1's own missing licence goes unmentioned.
> Under the treatment above the stakes are mild — `conditional` never nags, it only
> puts the word in a one-line list — so the real question is narrow: *should an
> unlicensed business see "licence" once among things it does not publish?*

*Correction, recorded so it is not re-derived: an earlier version of this reasoning
claimed the setup-blocks-above-when-unconnected rule needs to know when setup is
finished and cannot. That was wrong — it keys off `connected_at != null`, whether a
plugin has ever read the site, which the module answers exactly. The tier decision
rests on the opposite-facts argument alone, which is sufficient on its own.*

### 5 · Web Management · Widgets — the colour pairs shipped unchecked — **BUILT `24a5d314`**

`client/lib/screens/web/widgets_tab.dart`. "Accent colour" and "Text on the accent" are two adjacent free-text hex fields
whose whole job is to be legible against each other, and nothing checks that they are.
Everything else about that screen is careful — the values are sanitised on the way in
AND on the way out, colours are `#rgb` or nothing, the font is stripped, the radius
clamped — because the output lands in a `<style>` block on a site we do not serve. The
guard that is missing is not a security one; it is the design one.

Accent `#ffff00` with on-accent `#ffffff` is 1.07:1 and saves cleanly today. The widget
where that ships is a **lead capture form on the customer's own website** — the one
surface where an unreadable button costs them the enquiry, on a page nobody at EzBizi
will ever load.

**This does not want a palette, a token export or a colour system.** Flutter already
ships `Color.computeLuminance()`, which does the sRGB linearisation; the check is the
ratio and a warning row:

```dart
double _ratio(Color a, Color b) {
  final x = a.computeLuminance(), y = b.computeLuminance();
  return ((x > y ? x : y) + 0.05) / ((x < y ? x : y) + 0.05);
}
```

Warn under 4.5:1 on the pair — **warn, never block.** A customer's brand may genuinely
be low-contrast, and this is their site, not ours; the screen's job is to tell them what
a visitor will see, not to overrule them. Derived at render from the two fields and
never stored, per the standing rule that a warning about the system's own capability is
DERIVED. One caller today, so it belongs in that file — promote it to a shared helper
when a second colour picker exists, not before.

**Built by UI/UX, 2026-09-08, `24a5d314`.** Written up first as a finding to hand over,
because the file was on `session/web-management` and mid-flight. It merged **to main**
while that message was being written, so by the time it sent, the reason for handing it
over had gone: not a peer's private branch any more, but a shared file with the pair
unchecked. Built it and told them, with an offer to back it out if they had the same
work going.

> **Correction, same evening, from the deploy desk.** The paragraph above first said the
> file had *shipped* in 0.0.0167, and it had not. I read a commit whose subject line was
> `0.0.0167: …` as the release having happened; it was the desk writing the notes, and
> the cut was still in its gates. 0.0.0167 is in fact pinned at `24a5d314`, so the widget
> screen goes live for the first time **with** the guard — the "released without a check"
> gap I described never existed. **A commit that names a version is not that version
> shipping**, any more than a green release script is a published site. It cost nothing
> here because the reason for building was the merge to main, not the release; had the
> release been the reason, the whole justification would have been fiction.

**Two things only visible from inside the file**, and the reason a finding written from
the outside should not be trusted to be complete:

- **Two pairs, not one.** `text` on `background` has the identical defect to `accent` on
  `on_accent`. Fixing the named one would have been the tidier commit and the wrong one.
- **The screen renders none of its four colours anywhere** — no preview, no swatch. So
  the customer was not merely unwarned, they were choosing blind, which is why this
  became a swatch of the real pair rather than a warning line. The ratio shows always;
  only the advisory is conditional.

Not `ErrorNote`, which documents itself as *"a failure the user needs to read"* — this is
not one. Nothing failed and nothing is blocked, and **dressing an advisory as a failure is
its own defect**, the same family as a silent default between two unequal errors.

Accepts `#rgb` as well as `#rrggbb`, matching `WidgetDefaults::colour`: a checker that
knew only the long form would pass every short one **silently**, since saying nothing is
also what it correctly does mid-typing. That branch was sabotaged to confirm the test
fails without it.


### Suggestions · two screens out for decision

Six numbered files, two independent answers. **No number is chosen and none is recorded
here.** Everything below was read out of `f13dd81..f845c4e` rather than inferred from the
drawings, and it holds whichever number comes back.

- **The author's list is ordered by `created_at` and by nothing else.** A decided
  suggestion sits above one still in review whenever it was sent later. Any grouping by
  state is the client's own arrangement, and layout 2 is the only one that does it.
- **`decided_by` is stored and is not in the author's payload.** So the answer is
  EzBizi's word, unsigned. A layout that draws a reviewer's name draws a field that never
  arrives.
- **Edit and Withdraw exist on a `new` one and on nothing else**, and the three later
  states must *say* why rather than silently drop the buttons — the server's own two
  sentences from `SuggestionsController::frozen()` do that job in all three layouts.
- **Marking one "In review" must not offer the note field.** `decide` writes any note it
  is given into `decision_note`, and the author's payload carries `decision_note`
  *whatever the status is* — so a note typed beside "In review" turns up on the author's
  screen with no notification and no explanation. All three queue layouts make it a bare
  button. **This is the `usable` shape again**: one column answering a second question.

  **SETTLED DIFFERENTLY, 2026-09-04, and the requirement is met — read this before
  filing it as unbuilt.** The shipped screen *does* draw the field, because the drawn
  footer is shared with Approve and Deny, which need it; hiding it for one of three
  actions would take a control away from the two that use it. Instead "Mark in review"
  is **disabled while the box has content**, with the reason in place of the button's
  usual hint: *"sends no note — approve, deny, or clear the box to use it"*. Nothing
  typed is discarded, which was the failure worth preventing.

  The half of this that mattered is now **structural rather than drawn**:
  `StaffSuggestionsController::decide` refuses a note with `status: reviewing` outright
  (`a0119847`) — *"In review tells them nothing — this note would show up on their
  screen with no notice. Approve or deny it if there is something to say."* So the
  guarantee no longer depends on a screen at all. That is the point: the previous
  containment held because a button was disabled, and a disabled button is a
  convention, not a guarantee. A module whose whole design rests on `ModuleRegistry`
  absence being structural should not hold its other invariant by habit.
- **Approving with no note is legal and should not be.** The notification's body *is* the
  note, so an empty one sends a bell reading "Your suggestion was approved" with nothing
  under it. The server permits it; the screen presses for a sentence anyway.
- **Denying with no note is refused** — *"Say why, in a sentence. The person who sent it
  reads it."* A 422 on `note`, so it is reported at the field and retried with the same
  button. A refused **save**, never a reload.
- **The queue is `limit(200)` with no paging and no search parameter.** No total may be
  promised and no page 2 drawn; filtering is the client's, over what already arrived.
- **A decided one can be decided again** — a reversal replaces both status and note — so
  the decide control has to stay reachable on a decided row rather than becoming a receipt.

> **One thing for Jose that is not about these screens alone.** This is the **seventh chip**
> in the Development tab strip, and `TabBarLike` puts its chips in a horizontally scrolling
> row — against the settled rule that chrome *wraps* rather than scrolls. It is a
> `SingleChildScrollView(Axis.horizontal)`, so it scrolls *by construction* rather than
> only when it happens to be too wide, and the six labels already there do not come near
> fitting 375px. A seventh **makes it worse rather than causing it**; it is
> pre-existing, the same shape as the `isExpanded` overflow. The fix is one line, but
> `TabBarLike` is shared with Automations, so it changes two screens. All three queue
> drawings show the strip wrapped, which is what fixed looks like.
>
> **DECIDED 2026-09-04: not changing it, and this is a decision rather than a gap.**
> Jose put the three open Suggestions items back to me; on this one I left the widget
> alone. Two reasons. It is shared with Automations, so a session that owns neither
> would be restyling another module's tab strip without that session in the room — and
> the drawings showing it wrapped are drawings of *Suggestions*, which is not a mandate
> to restyle Automations. And Development is a staff control plane used at desk width,
> where a strip that scrolls is an ordinary pattern rather than a defect somebody hits.
> The settled rule it offends is real, and the honest position is that this is a
> **known deviation with an owner-shaped gap**: whoever owns `TabBarLike` should fix it
> once, for both screens, rather than a module session doing it sideways. Recorded here
> so it stops resurfacing as an unanswered question every time somebody counts the chips.

---

## Naming, because it will bite whoever reads this

Two adjacent dashboard panels must not share a word for different facts. HR's
**"Expiring certifications"** counts date fields a *contract template* marked
`is_expiry` — a licence the employee typed at onboarding, **theirs** to renew.
Training's counts our own test passes lapsing, **ours** to re-assign.

> Different table, different fact, **and a different person responsible for acting on
> it.** That last clause is the real argument, and it is stronger than the naming one:
> **two numbers that summon different people must never be one number**, whatever they
> are called.

Settled with Jose: Training never says "certification" and says **"due for retaking"**.
They stay two panels. Carry the wording into field names too — `people_due_for_retaking`
rather than `people_expiring` — so the wrong word cannot leak back in through a variable
three modules later.

### Training home glance — endpoint shaped before it locked

`GET /training/dashboard` returns `{id, title, assigned, passed, in_progress, overdue,
retaking}` **per guide**. Correct for the module's Dashboard tab, which is a list of
guides. **A home glance cannot be built from it.** Summing `overdue` across guides counts
*assignments, not people* — one person overdue on three guides sums to 3, and an owner
goes looking for three people who do not exist. De-duplicating a person across guides
needs the join; the client does not have it.

Asked for, alongside the array rather than instead of it:
`summary: { people_assigned, people_overdue, people_due_for_retaking }` — distinct people,
org-wide, same permission reach. If only one survives, `people_overdue`; it is the one an
owner acts on.

*Caught inside a closing window — after Task 11 it becomes a contract four Flutter
steps consume. Worth asking every module session what a home glance would need **before** its
module dashboard endpoint reviews clean, because the glance always wants a roll-up the
per-row shape cannot answer.*

## Approved designs that predate the role — do not reopen

Both were approved by Jose directly, before the role existed, through the same
shape of process. Reopening either is his call, not design's.

- **Training** — eight numbered options, picked by number: Learn 1, Guide player 4,
  Dashboard 6, Settings 8. Committed at `docs/previews/training-layouts.html` (`7c65a53`).
  *Open question worth Jose's attention, not a change request:* Settings layout 8 is a
  storyboard strip, a pattern that exists nowhere else in the app — every other module
  uses list-beside-editor. It was flagged to him and he chose it anyway.
- **Web Management · SEO panel** — two-pane list/detail, 288/900, shortcode as a copy
  **button** rather than helper text, setup blocks above the fields when a site is
  unconnected and below once connected. Shipped in 0.0.0118. The last two generalise
  and are worth stealing: a value whose only purpose is to be pasted gets a copy
  button, and position can carry "is this what you should be doing right now".
