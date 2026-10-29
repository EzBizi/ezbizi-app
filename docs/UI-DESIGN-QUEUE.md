# UI/UX — the queue, and what has been settled

> **⚠ A STATUS IN THIS FILE IS A CLAIM ABOUT THE WORLD, AND FOUR OF THEM WERE FALSE ON
> 2026-09-15.** Entry 18 read *DRAWN, awaiting Jose* hours after he picked and it shipped; Leads
> connected accounts read *AWAITING JOSE* while the screen was built, grouped and searchable;
> entries 7 and 8 read *DRAWN, with Jose* with 311 and 413 lines of the thing on main.
>
> **The cause is structural rather than carelessness.** CRAFT ships and tells UI/UX after, other
> sessions build directly on his word, and nothing makes a row update itself. So the rot is the
> normal state of this file, not an accident in it.
>
> **Before treating any row as open, spend one command proving it is:** find the file that would
> exist if it were built. `grep -rln` on the feature, then `git log --oneline -1 --` on what comes
> back. That is the whole check, and it would have caught all four.
>
> **And never put a gallery in front of Jose without running it first.** The worst outcome here is
> not an untidy file — it is asking him to choose between six layouts for a problem somebody
> solved and shipped while the row sat here saying it was waiting for him.

> **REPORTING RULE, Jose 2026-09-15 (`CLAUDE.md` `0c90472e`, verified): replies to him are 3
> bullets max** — final result, anything broken, anything he must do, a real question. His named
> complaint is *"endless summaries replicated on all sessions"*. Peer messages, layout
> measurements and these entries stay full-length; the constraint is only on what reaches him.
>
> **Scheduling reshaped the capacity settings screen** on his direct instruction and notified
> UI/UX rather than queueing it — the approval flow working as written. Rides 0.0.0214. Not drawn
> here; recorded so this queue does not read as if that screen is unchanged.

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

**No answer in 10 minutes means the recommendation.** Jose, 2026-10-06: *"from now on just always go with ur
recommendation if i dont answer in 10 mins."* Send the gallery; if he has not picked within 10 minutes, say so in the
entry ("no answer in 10 min — the recommendation, per Jose's standing rule") and build the recommended options.

**The danger is not the error. It is that the artefact looks finished.**

Training's, 2026-09-09, drawing three of that day's findings into one shape. Each is a
different medium and the same failure — **the thing that hides the mistake is the very thing
that signals care**:

- **A wrong reason survives review**, because it reads as considered. No reason invites the next
  reader to work it out; a wrong one hands them an argument, and they fix working code to match
  it.
- **A correction appended to a note leaves the false summary in play.** The body is right, the
  one line a reader sees at recall is not, and nothing looks unfinished.
- **A true sentence resting on an unstated dependency reads as settled.** *"This organization
  has no roles yet"* is correct only while roles are org-wide; the day one gains a franchise
  dimension it is false and **nothing fails** — no test, no analyzer, no reader who could tell.

> **So the review question is not "is this right?" but "what would this look like if it were
> wrong?"** In all three cases: exactly the same. A wrong sentence at least looks wrong to
> somebody reading the file; a correct-by-dependency one looks like a decision already taken.
>
> The practice, in all three media: **write down what the thing depends on, beside it.** The
> reason under a decision, the current fact at the TOP of a note, the dependency beside a
> deliberate world-claim. Not for the author — for the reader who has no way to tell a
> considered artefact from a finished-looking one.


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

**Bound a resizable pane in PIXELS, not in a share — and check the floors can both be met.**
Leads, 2026-09-09 (`06519f20`), making the Leads divider draggable: *"a share alone let a wide
window push the thread to 336px, and the header overflowed by 46."* A percentage says how the
room is divided; it cannot say what either side needs. The floors are `320` for the list and
`460` for the thread, the second being what its header wants for the star, Book and the status
control on one line.

> **The check that is easy to skip: two floors can be unsatisfiable together.** `maxList =
> (width - 460).clamp(320, width)` returns 320 on a narrow window and hands the thread 380 —
> the exact overflow the floors exist to prevent, reintroduced by the clamp. It is unreachable
> here only because the split renders at `>= 900` and 320 + 460 = 780. **A pair of minimums is
> a claim about the SMALLEST container they will ever run in**, so verify against that number,
> not against a comfortable one.
>
> Set the floor above the measured minimum, not at it: 382 was what overflowed, 460 is what
> shipped. A floor at the measured value clears the case you tested and nothing else — the same
> lesson as the row heights where a 44 floor left two heights one pixel apart.

**Reuse across a module boundary beats a polite copy.** Same commit: the Leads call row now uses
Phone's `RecordingPlaybackBar` and **deleted** its own play disc rather than adding a second
player. One definition, in `screens/phone/`, imported by `lead_thread.dart`. A copy would have
been the easy version and would have drifted the first time Phone improved scrubbing. **Deleting
a widget to adopt someone else's is a better outcome than either module writing its own**, and
it is the thing sessions avoid out of deference to each other's folders.

**A placeholder that says "will" is a promise; one that says where it lives is an answer.**
Ruled 2026-09-09 on the dashboard's coming-soon boxes. Newsletter's entry and its comment
agree — *"Newsletter keeps its own dashboard inside the tab… Open the Newsletter tab for it"* —
and the reasoning holds: a second summary a click from the real one restates it and then
drifts. Training's entry does **not** agree with itself. Its comment says *"no plan puts a
summary of it on the home screen, so a placeholder is the honest answer rather than a temporary
one"*; its sentence says *"Training **will** show…"*. **Only the sentence is on screen.**

> **The test for a placeholder: does it describe a destination or a date?** "Will show" is a
> date with the number left off, and a reader is owed the thing eventually. "Keeps its own
> dashboard inside the tab, open it" is a destination and is true forever. A placeholder whose
> comment says permanent and whose copy says coming-soon has picked a fight it will lose in
> front of a customer.

> **And the drift objection is about a SUMMARY, not a COUNT.** It rules out restating an in-tab
> dashboard on the home screen. It does not rule out the one number that makes somebody open
> the tab — a count cannot drift from a dashboard because it is not describing it. Training's
> "due for retaking" is shown one person at a time on the HR record card, so the org-wide
> figure is invisible unless you go looking: exactly the shape a glance is for.

**Right about the effect, wrong about the mechanism — and the mechanism is what a peer
builds on.** Dashboard, 2026-09-09, and the best process note anyone gave me today. Scheduling
asked whether the dashboard handles a chart with real categories and a zero in every one.
Dashboard checked, formed the answer *"the chart is not built"*, and — before sending —
**pinned it as a test. The assertion failed.** `DashboardChart` **is** built; `_chartable`
only asks whether the slice list is non-empty, and it is `build` that returns
`SizedBox.shrink()` (`dashboard_chart.dart:44`, verified). The visible effect was right and
the cause was wrong.

> **This is worse than being wrong about both, because the correct effect supplies the
> confidence.** A peer does not build on the effect, they build on the mechanism — Scheduling
> would have designed around a widget that does not exist. **Before handing a peer a rule about
> your own surface, write the assertion that would fail if the rule were wrong.** Two tests and
> no production code turned a wrong answer into a measured one.

**Two byte-identical copies of `_chartable` decide whether a chart draws.**
`dashboard_screen.dart:495` and `hr/dashboard_tab.dart:355`, same switch, same four kinds.
Nothing is broken today; both agree. But the set of presentation kinds MOVES — Dashboard
changed which ones a glance may offer this same day — and the day a fifth kind is added to one
copy, a chart draws on the home screen and silently does not on HR's, falling through
`default: return false` with no error anywhere. **Same drift class as two definitions of readable.**

> **CORRECTED the same evening, and my answer was wrong.** I recommended a test asserting the
> copies agree. HR differed out loud and was right; the measurement then made it decisive.
> **They are not copies of one rule — one already disagrees:**
>
> ```
> _chartable   (both screens)  'pie'||'bar' → slices is List && isNotEmpty
> isDrawable   (the chart)     'pie'||'bar' → slices.any((s) => s.total > 0)
> ```
>
> A glance with real categories and a zero in every one — the exact case Scheduling asked
> about — makes the screens say yes and the chart say no. **An agreement test would have pinned
> a live contradiction in place**, or been written loosely enough to pass and enshrine the
> weaker rule.
>
> My argument was that the two dashboards might legitimately diverge one day. They cannot: the
> chart decides what it can draw, so a screen disagreeing with it is a bug with a nicer name.
>
> **And the fix is smaller than either proposal.** `isDrawable` is strictly stricter than
> `_chartable` in every branch, and a non-drawable chart already returns `SizedBox.shrink()`
> (`dashboard_chart.dart:44`) as a bare list child with no wrapping padding. **Deleting
> `_chartable` from both screens changes what a person sees in exactly zero cases** — two
> definitions removed, none added, no new API to keep in step.
>
> **The general form: before writing a test that two things agree, check that they do.** An
> agreement test is only a guard if it starts green for the right reason; started against these
> three it would have been a decision about which of two rules to canonise, taken by accident.

**A test written against a guard outlives the guard and defends it.** HR, 2026-09-09,
deleting the duplicated `_chartable` (`92014469`). Their own test read:

```dart
expect(find.byType(DashboardChart), findsNothing);   // before
expect(tester.getSize(find.byType(DashboardChart)), Size.zero);   // after
```

**`findsNothing` was not testing the behaviour — it was testing that the guard existed.** The
widget is absent only because a caller decided not to build it, so deleting the caller's rule,
correctly, made the test red. A correct simplification reads as a regression, and the person
doing it has every reason to believe the guard was load-bearing.

> Candidates are everywhere: **`findsNothing` on a widget that can render nothing by itself**
> is almost always asserting a caller's decision rather than what a person sees. Ask what the
> reader would notice — nothing occupies no space — and assert *that*: present and `Size.zero`.
> The version that measures survives the guard being removed, and fails if the widget ever
> starts taking room.

**A box that vanishes when the news is good cannot say "I looked, and it is fine".** Ruled
2026-09-09 on Training's due-for-retaking glance. Zero renders as a sentence — *"Nobody is due
to retake a test."* — and the empty state is reserved for "there is nothing to count at all".
Training's own reason was layout: a box that emptied at zero would reflow the board every time
the last person retook something. The stronger one is that **zero IS the answer the box exists
to give**, and absence cannot distinguish "checked, all clear" from "broken".

> Corollary, and the thing to actually check: **the zero sentence and the empty sentence must
> not read alike.** *"Nobody is due"* and *"nothing to count"* are different facts and a reader
> who cannot tell them apart has learned nothing from either.

**Scoping the CONTENT constrains nothing about the PEOPLE.** Training's note, and the sharpest
privacy point anyone made today: a count over people is a disclosure even with no name in it,
and an organization-wide test is visible to everybody — so bounding the glance by reach over
the *test* leaves the headcount unbounded. Bound it by reach over the **person**. Their test
uses an org-wide test *deliberately*, so content-scope cannot mask the bug, plus a positive
control so a box stuck at zero cannot pass. **This module had already shipped two
cross-franchise disclosures by conflating those two questions.**

**~~Customer copy may not borrow our scoping vocabulary.~~ REVERSED the same evening — the
measurement was wrong and the ruling flipped with it.**

I ruled Training's *"No tests in reach"* out on the grounds that `reach` appears **zero** times
in customer-facing copy. That measurement was of `ui_strings.dart`, and it was correct about
that file. **Glance copy lives in PHP**, one Glances class per module, which the search could
not have reached. Measured properly: *"in reach"* is in **11 files** — *"No vehicles in reach."*,
*"1 customer in reach."*, *"No jobs in reach."*, and more, all on the same board. Training was
joining established house vocabulary, not introducing a word.

> **And on the corrected facts my objection was the feature.** I said "in reach" half-implies
> an *out of* reach the reader is not entitled to know about. It does imply it, and the reader
> **is** entitled: these counts are scoped to them, and the phrase is what stops *"12 customers"*
> being read as the organization's total rather than their own. For an empty state it is the
> more honest of the two — *"No tests in reach"* does not claim the organization has none, and
> *"No tests to track"* does. **Ruled back to "in reach."**

> **RULED AGAIN, and this time on the alternative rather than the presence — drop it.** The
> deploy desk re-opened it with a full count: **eleven customer-facing strings**, not eight, and
> three of them not on the dashboard at all. But the fact that decides it is one neither of us
> had measured: **the same board already writes the plain form.** `LeadsGlance` says *"No
> enquiries yet."*, Reviews says *"No reviews yet."*, *"No star ratings yet."* So "in reach" was
> never house style — it is a habit in one cluster of boxes, sitting beside boxes that phrase it
> plainly. **The consistency I reverted Training to protect does not exist.**
>
> My middle position failed for a reason I could have caught: **I measured the phrase's presence
> and never measured its alternative.** Eleven files saying a thing tells you it is common, not
> that it is the convention. **When you justify a phrase by consistency, read what the
> inconsistent neighbours say.**
>
> **The rule for the replacements, which is what actually makes them safe: the empty sentence
> describes the BOX, not the world.** *"No tests to track."* describes what the box holds and is
> true under any scope. *"No tests yet."* describes the organization and is a claim the box
> cannot support when the reader sees a subset. That is the real content of my original
> objection, and it means Training's first line was right for a better reason than I gave it.
>
> **Two strings keep it, and that is a ruling rather than a deferral.** `ActionRunner:671`
> (*"No franchise is in reach for that phone line, so a job cannot be opened."*) and
> `ModuleServiceProvider:1409` are not scoped counts — they explain a mechanism to somebody
> configuring an automation, where "in reach" names the thing being explained and dropping it
> would change the meaning to "none exists". Different reader, different job.
> (`ActionRunner:643` is a docblock, not copy: the count is eleven, not twelve.)

> **FOURTH pass, and the ruling is SIXTEEN not ten. The deploy desk stopped the sweep to say
> my decisive fact was wrong, and it was — I cited as precedent the very form my own rule
> forbids.** I justified dropping "in reach" by pointing at *"No enquiries yet."* and *"No
> reviews yet."* as the board's plain form. **"Yet" describes the organization**, which is
> precisely the claim I said a scoped box cannot support. Fleet and Services found it; Inspection
> gave the case that settles it — their glance is franchise-scoped and closed two leaks
> yesterday, so *"No inspections yet"* would be actively false to a franchise reader.
>
> **The board has FOUR habits, not two**, read off `'sentence' =>` on main:
> *in reach* (5) · *yet* (6) · **to show / to track** (Phone, Training) · **in this view**
> (Marketing). **The form I recommended was already on the board** — `PhoneGlance` says *"No
> phone lines to show."* — so this is not a new convention, and Phone is already compliant
> rather than a target, which the hand-count had wrong.
>
> **All six "yet" boxes are scoped, so there is no exemption.** I nearly granted Marketing one:
> a grep for `reachable|franchise|company_id` returned **zero** for `MarketingGlance`, and it
> scopes with `$this->access->inView($user, $c)`. **A fifth false absence from a pattern that
> did not know the word** — and the one that would have exempted a box from a rule it needs.
> Marketing's own file proves the rule: *"No accounts connected in this view."* sits beside
> *"Nothing posted or scheduled yet."* in the same glance.
>
> **"In this view" stays and is arguably the best of the four.** The rule is not "no
> qualifier" — it is *no qualifier the reader cannot parse, and no claim about the world*.
> "In this view" says the count is scoped, in words a customer already owns; "in reach" says
> the same thing in ours.
>
> **Count lines take Services' participle form** — *"No services listed." / "3 services
> listed."* Services measured all 23 count sentences across 13 glances: **every one carries a
> qualifier and not one is bare**, so the desk's proposed *"12 vehicles."* would have been the
> only bare count on the board. Their shape also matches Reviews' existing *"1 review
> unanswered."* Adopted as the house form.

> **The measurement lesson, which is new and is the reusable part: the pattern was right, the
> file was right, and the CORPUS was wrong.** Dashboard copy is split across Dart and PHP, so a
> clean search of one language can miss most of the thing and return a confident zero. Before
> ruling that a word is absent from customer copy, ask **where customer copy lives** — plural.

**The smallest correct diff can still be the wrong one: check what it does to the
assertions.** I told Dashboard to delete `_chartable` outright and let every screen build a
chart that shrinks itself — correct in behaviour, zero visible cases, two definitions removed
and none added. **Dashboard overruled me and was right.** The cost was not correctness, it was
legibility: **five `findsNothing` assertions across two test files** would have had to become
"present at zero size" — *the exact formulation that had already made them tell a peer the
chart widget was not built when it was.*

Their answer keeps one definition and two ways in: a private `_drawable` switch reached by
`DashboardChart.canDraw(presentation, data)` for a raw payload and `isDrawable` for parsed
lists (`dashboard_chart.dart:165`, `:43`, `:248`). **Keeping the question askable keeps absence
expressible as absence.**

> This does NOT contradict [[a test written against a guard outlives the guard]] — it locates
> it. That rule bites when the guard is a SECOND DEFINITION, so a test on it defends a
> duplicate. Once the guard is the single definition's public face, `findsNothing` is a true
> statement about the tree and the honest assertion. **I optimised for fewest lines and did not
> price what it did to five tests.**

**Duplication that fails VISIBLY is worth less to remove than duplication that fails
SILENTLY.** Dashboard left `_labelFor` and `_iconFor` duplicated across the same two screens,
deliberately: a missing kind there shows a raw key or the wrong icon **in front of somebody**,
where `_chartable` diverging showed a correct-looking screen that was wrong. Endorsed — the
same discriminator that decides everything else in this file. Shared API is a cost, and it
buys most where the failure is invisible.

**Sabotage a file you have already committed, or copy it aside first.** Dashboard proved their
ruling by reverting the rule and watching only the right test go red — then restored with
`git checkout --` on a file that still held uncommitted work, and threw that work away with the
sabotage. The technique is safe on a clean file and a footgun on a dirty one. (`cp` to the
scratchpad first is what made the same technique safe for me three times the same day.)

**A summary repeating into its own detail is context, not duplication — but a one-item list
is not a sequence.** Ruled on Training's Attempts block, 2026-09-09. It draws from the SECOND
attempt onward, which is right; the reason given for it was not. *"With one attempt the tag
above already is the history, so repeating it says one fact twice"* argues for excluding the
current attempt at **every** count, and at two the latest is still both the tag and the first
line. The reason that holds is that with one attempt there is no change to show, so the heading
has nothing to head. **Fix the reason, or the next reader "fixes" the code.**

**An unbounded list inside a ROW is one person deciding how usable a screen is for everyone
else.** The Attempts block sits in `_PersonRow` inside a `ListView.separated` — a list of
people. Somebody who sat a test forty times produces a row that swallows the pane, and the cost
lands on whoever is scrolling past looking for somebody else. **Capped at three, newest first,
with the affordance NAMING THE TOTAL** — *"Show all 9 attempts"*, so the reader knows the size
of what is hidden before opening it. Same discipline as an empty sentence describing the box.

> Jose's word was *"all"*. A cap with an affordance that names the count still offers all of
> it — "all" answered *what the dashboard holds*, against a version that showed only current
> state. **CONFIRMED by Jose, 2026-09-09, in his own words: "whole history available."**
> Available, not necessarily all rendered at once — which is precisely what a cap plus a
> named "Show all 9 attempts" delivers.
>
> **Stating the reading is what got it confirmed in one word.** Had I asked "cap at three?" he
> would have had to reconstruct why; naming the interpretation let him rule on the thing that
> mattered and leave the number alone.

**Craft ships and tells me; a new element is design; Jose's direct word is neither — it is just
the job.** Training routed the Attempts block as craft. It is a new element, so normally design;
what makes their route right is that **Jose ruled it directly**, not the craft carve-out. Worth
keeping separate so the carve-out does not quietly widen to cover anything a module felt sure
about.

**The rule is about the CLAIM, not about two phrases.** The sweep found eighteen strings, not
sixteen, and the last two were invisible to every grep because they used neither forbidden word.
Phone's box said **"No calls came in."** — false for anyone whose lines are a subset, a
world-claim in plain English. Dashboard found the client's last-resort fallback saying
*"Nothing to show yet."*, and their argument is the sharpest of the whole sweep: **that branch
is reached precisely when we do not know what the box holds.** Every other empty sentence is a
module making a claim it is in a position to make; that one was the app guessing optimistically
about somebody else's data. **Grepping for "in reach" and "yet" finds the habits, never the
rule.**

**ComingSoon is a different question and it was already answered.** Ruled 2026-09-09: those
sentences are not empty-state copy — a coming-soon box has no query, so it makes no claim about
the customer's data at all. It falls under the placeholder rule above (*describe a destination,
not a date*), not the scoping rule. **Measured, the set is two strings, not a category:**
Newsletter's is compliant, and `Monitoring` still carries both — *"Monitoring **will show** who
is online … here. Open the Monitoring tab for the roster."* One promise, one destination, in one
sentence. Drop the promise, keep the destination. **Dashboard's to reword, one string, no
sweep.**

**A phrase can be simultaneously the best evidence available and inadmissible.** Customers'
line, and the whole story of this ruling: I cited Leads and Reviews as the board's plain form,
and those strings break the rule I was citing them for. Web Management then shipped my
pre-ruling wording because they checked my cited precedent and stopped there; Inventory nearly
confirmed a verb against Services' strings, which had not landed. **A ruling titled "I cited as
precedent the form my own rule forbids" deserves a precedent that exists** — cite `025f5afd`,
the only participle live in a glance.

**The empty-sentence rule is a CORRECTNESS question outside the glances, and the sweep was
only ever the copy half.** Training measured their own module against it — eight strings, none
of them glances, none in either count — and found four non-compliant, one of them **false**
rather than untidy: `dashboard_pane.dart:198` said *"Nobody is assigned to, or has attempted,
this yet."* in a pane **bounded by reach over the person**, so a franchise manager is told
nobody is assigned while people are assigned and simply not theirs to see. Training is one
module of seventeen.

> **Ruled: separate the two, and do not sweep.** The glance strings were a copy decision and
> they shipped. In-screen empty states get **the discriminator stated once**, each owner
> applying it to their own module: *is this list filtered by who is asking? Then the sentence
> may say what showed up, never what exists.* Anything **false** to a scoped reader is a bug and
> is fixed on sight; the merely imprecise waits until somebody is in the file. A
> seventeen-module coordinated sweep is a large freeze for a class that is mostly imprecision
> and occasionally a defect — fix the defects now, let the rest ride.

> **And the COMPLIANT ones are the fragile ones, which is the part nobody expects.** Training's
> *"This organization has no roles yet."* is correct **only while roles are org-wide**. The day
> anything gains a franchise dimension, its world-claim becomes false and **nothing fails** —
> no test, no analyzer, no reader who would know. **A deliberate world-claim should carry a
> comment saying what it depends on**, because it is the sentence that will rot silently while
> the non-compliant ones at least look wrong.

**A wrong reason is worse than no reason, because it survives review.** Training's line,
2026-09-09, after two of their comments in one day stated a true conclusion with the wrong
argument under it. **No reason invites the next reader to work it out; a wrong reason reads as
considered, so they inherit the argument instead of the question** — and then "fix" working code
to match it. Both of theirs would have done exactly that: one argued for dropping the current
attempt from a list at every count, the other defended a string on a consistency that did not
exist. **When correcting a decision, correct the reason in the file, and name the discarded one
so nobody re-derives it.**

**A one-way disclosure is not a finished control.** Training's *"Show all N attempts"* was
`setState(() => _showAll = true)` — expand once, no way back short of a reload. That
reintroduces the exact failure the cap was ruled for (a row swallowing a list of people), only
now the reader causes it and cannot undo it. **Ruled: the same control toggles, label swapping
to "Show fewer".** That is not the second control they were right to avoid inventing — it is the
first one completing its own behaviour.

**State inside a row of a recycled list belongs to the SLOT, not the item, unless keyed — and
the danger is at the SECOND edit, not the first.** Training's refinement, and it is the part
that makes the rule useful: *"adding state to something inside a ListView is a moment where the
question announces itself. It would not have announced itself to somebody adding a filter to
that list a month later — the row would already be stateful and already look finished."* **The
person who adds the state usually sees it; the person who adds the reordering usually cannot.**
So this is a fourth member of the *looks finished* family: a stateful row reads as already-solved
to whoever arrives next.

**A test written from the same mental model as the code inherits its blind spot.** Training's
one-way *"Show all"* shipped with a test that tapped once and asserted the list opened — which
**passes on the broken version**. The control that was wrong and the test that could not see it
came from the same gap, in the same commit. The two-tap test asserting both directions is what a
different mental model would have written. **Where a control has a state machine, assert the
return path, not just the departure.**

**A key is copy too.** Renaming `show-all-attempts` to `toggle-attempts` when the control gained
a second direction: *"a key called 'show all' on a control that also hides is the same small lie
the empty states spent today losing."* Test keys are read by people, and one that describes half
a control ages exactly like a sentence that describes half a fact.

**State inside a row of a recycled list belongs to the SLOT, not the item, unless keyed.**
Training keyed their person row after finding that expanding one person's history and then
filtering would show *somebody else's* row expanded — `ListView` reuses the element, and the
expansion state stays with the position. **It would have been reported as a design bug**, and
the fix is a `ValueKey` on the identity rather than anything in the layout. Candidates: any row
holding expansion, selection or draft state inside a list that filters or reorders.

**Scaling to fit does not make something smaller, it makes it unreadable — and it still costs
the space.** Jose, 2026-09-10: *"the ezbizi logo itself is impossible to see."* The cause is not
truncation. `main.dart:934` wraps the brand row in `FittedBox(fit: BoxFit.scaleDown)` when the
title slot is under 240px, so at 375 the wordmark and the company logo are **shrunk past
legibility rather than dropped**. A mark nobody can read occupies its width and communicates
nothing, which is strictly worse than absence.

> **`FittedBox` is the right tool for something that must stay whole and the wrong one for
> chrome.** On a phone, decide what to DROP; scaling is what you reach for when you have not
> decided. The test: if it were absent, would anyone notice? If not, it should be absent.

**A state is not a control, and states have a strip of their own.** The same bar carries two
CONDITIONAL chips — two-factor-required and read-only — inline among the buttons, so the worst
case is worse than any screenshot shows: measured at 375 it runs off the right edge. `main.dart:
619` already describes a one-line strip under the AppBar as *"the same family as the two-factor
chip"*. **The home exists; the chips are simply not in it.** Whatever else is decided about that
bar, the conditional chips belong under it — they are things that are TRUE, not things you press.

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
> it reads as "a form", not as any particular company.
>
> **Stale as of 2026-09-09, and left visible rather than rewritten.** This paragraph
> used to say we hold `logo_path` and *no brand colour anywhere*. That was true when
> measured and is the fact that decided the question — it is why the answer required
> BUILDING a field rather than reading one. Jose then chose customer-brand-first, and
> Web Management added `brand_colour` beside `logo_path` at `a4e9c016`. So `#0b5cad`
> is now the **fallback**, not the default, and it keeps this entry's reasoning: a
> business that has told us no colour still gets something legible that is nobody's
> brand. See item 7.
>
> What does travel is the **rule, not the token**: a foreground and its background are
> chosen together and checked together. Queue item 5 is that rule applied — both colour
> pairs on that screen were shipping unchecked, and now show the ratio a visitor gets.

---

## Known gaps in the four cross-cutting files

**CLOSED 2026-09-09 by Dashboard (`c4254b03`), in my file, without editing it.**
`tests/Feature/Dashboard/GlanceRegistryTest::test_every_sidebar_tab_has_a_dashboard_icon`
reads the Dart map with a regex and asserts set equality against `ModuleRegistry`'s sidebar
tabs — both directions, so a module with no icon fails and so does a key that outlives its
module. **Ruled strict, and it is structurally right rather than a judgement call:**
`dashboardPanelIcon` falls back `byModule[key] ?? byModule[key.split('.').first] ?? …`, so a
dotted glance key resolves to its parent's icon and never needs an entry. One icon per sidebar
tab is the map's whole job; there is no legitimate key that is not one.

> **The part worth copying is the control.** `assertContains('hr', $drawn, 'The icon map was
> not read at all.')` — without it, a regex that stopped matching would empty both sides and
> pass green forever. **A cross-language guard that reads a file needs a positive control or
> it is decorative.**
>
> One hole I found and fed back: the regex class `[a-z_]+` cannot match a dot, so a dotted key
> — the one shape the rule forbids — is invisible to the guard while being live at runtime.
> Ran it rather than reasoning about it. `[a-z_.]+` makes it fail as a stray.

**Constraining a shared file from outside, by adding a test rather than editing it, is the
right move and I would rather more sessions did it.**

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
| ✅ SHIPPED | **Leads · connected accounts** | **CLOSED 2026-09-15. It was built while this row said it was waiting on him, and both blockers recorded here are gone.** `docs/previews/leads-accounts-layouts.html` is a record rather than a decision now. Measured rather than assumed: `LeadsAccountsList` in `client/lib/widgets/leads_accounts.dart` renders **every** account, **grouped by lead count**, searchable, with placement editable in place — and search reaches THROUGH the fold, because a fold that hides a match hides rows from exactly the person who went looking. **Both payload gaps are closed:** placement renders, and `leads_count` is real server data (`LeadsController.php:924`), not a client-side guess — which was the whole question, since the count is what separates two accounts that matter from twenty quiet ones. Jose’s own sentence is in the widget’s docblock: *"no one will remember what the numbers mean"*, which is why a named row keeps its number too. **The lesson is the row, not the screen:** this sat marked as blocked on him through the day, and I nearly put six layouts in front of him for a problem that had been solved and shipped. A queue row is a claim about the world and goes stale exactly like any other. |
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
| 8 | **Inventory · General tab** | Briefed. `GET /inventory/summary` live at `4f8a9dec`. Two real payloads captured — see below. Not started. |

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

### 9 · Sidebar · rearranging and dividers — **BUILT** (desktop 3 + mobile 4)

`docs/previews/sidebar-rearrange-layouts.html`. Three desktop, three mobile, with the sidebar
search filter on a toggle because that is the case that breaks it silently.

**Jose's fixed-items constraint is already true and needs nothing built.** `_rail()` is called
three times (`main.dart:2042`, `:2079`, `:2109`), so Settings, Suggestions and Development are
not in the module list at all. They cannot be dragged into it because they are a different list.

**The asymmetry that shaped every option:** desktop is a `NavigationRail`, which lays out its own
destinations and cannot host a `ReorderableListView`; the phone drawer is already a `ListView` of
`ListTile`s where the stock control drops in. **Dragging is unnatural exactly where the sidebar is
a rail and natural exactly where it is a list.**

**Dividers carry NO name** (Jose, 2026-09-11: *"just be dividers basically"*). That makes a user
divider literally the `Divider` already shipping between the rails — no new visual, no copy,
nothing to localise, and no question about how a label truncates at 86px.

**Jose picked desktop 3 and mobile 4, 2026-09-11.** Built the same day.

The two rules below are enforced in ONE place — `client/lib/core/sidebar_order.dart`, which
turns a saved order plus the modules somebody actually has into rows and rule positions, and
back again. Everything downstream indexes the ordered list, so the shell, the rail, the drawer
and the arranger never learn that an order exists.

**The divider is what makes the rail dangerous again.** A `NavigationRail` cannot draw a rule
between its own items, so a divider cuts the module rail into more than one rail — the shape
the pinned and staff groups already had. A rail reports the index of the row tapped *within
itself*, so every group below the first hands back a number meaning something else. That is the
module-filter defect a second time, and it is pinned by
`client/test/sidebar_arrange_test.dart::tapping a row below a divider opens THAT row`, which was
measured red on a deliberate `onSelected(i)`.

*The rule test was aimed one case beside its mechanism and passed a sabotage.* Filtering away
what is **above** a rule never builds a trailing group, so the drop-empty-groups line was never
exercised; the case that fails is filtering away what is **below** it. Both directions are now
asserted. A green test proves nothing about the line it was written for until that line has been
broken under it.

> **Two rules follow, and they are not the same rule.** A divider needs something on **both
> sides**, and **two dividers cannot touch**. Filtering produces both: the first when a filter
> empties everything above a rule, the second only once every item *between* two rules has gone.
> Nameless dividers remove the orphaned-heading problem and leave these two.
>
> **And a nameless divider still needs a row to grab.** In the rearranging list it is a
> full-height row *containing* a rule, not a bare hairline — it is the one row whose only job is
> to be dragged, and a 1px target is both hard to hit and easy to miss.

**The silent failure to test for:** the rail addresses destinations by POSITION, so a reordered
*and* filtered list without a translation map opens the wrong module with **no error at all**.
The existing `shown` map solves this for filtering alone, on one rail. A green suite proves very
little here; the test has to go red on an off-by-one.

### 8 · Reports · the builder and the run view — **SHIPPED**

**Status corrected 2026-09-15 by measurement, not by news.** This read *DRAWN, with Jose* while `client/lib/screens/reports/report_builder.dart` was 413 lines on main, last touched by `341dc86f`. Built, shipped, and the row said it was waiting for him.

`docs/previews/reports-builder-layouts.html`. Three desktop, three mobile, with two toggles:
a person holding `reports.view` but not `reports.build`, and a report that returned no rows.
Backend is built (`session/reports`, `4fa40a4b`); there is no Flutter screen at all, and
`module_screens.dart` has no `reports` entry. Reports routed it here rather than shipping a
screen, which is correct — a builder is a new arrangement, not a state change inside one.

**Four rulings that hold whichever layout wins:**

- **Build and run are different screens, not one screen with a mode.** `reports.build` and
  `reports.view` are separate permissions, so a runner must get a list and results — never a
  builder with its controls greyed. A disabled builder tells somebody the app is for other
  people; a list of things they can run tells them what they have.
- **A phone RUNS; it does not build.** An unlimited-hop field picker at 375px is a worse copy
  of a thing that works on a desktop, and offering it invites starting a report you cannot
  finish. All three mobile options are run views, and one says plainly where building happens.
- **The two empty states must not look alike.** *"No reports built yet"* and *"This report
  found nothing"* are opposite facts and the same blank table serves both badly. **And the
  second must not read as broken:** a report can legitimately return zero for a narrow
  franchise reach and plenty for that person's manager, so it says so.
- **The 5,000 cap line disappears when there are no rows.** "The first 5,000" of nothing is not
  a truthful sentence about the box, and a red line under an empty table reads as a fault.

> **A second correction, and this one was in MY artifact rather than my note.** The first
> drawing showed a field tree reading *"Jobs › Customers › Franchises"*. **Franchises is not a
> reportable resource** — `ReportMap` declares three (`jobs`, `customers`, `users`) and exactly
> **one** edge. It also used a column called "Booked for", which does not exist; the real one is
> "Scheduled". **A gallery with invented data asks for a decision about a capability the product
> does not have** — Jose would have judged a layout partly on a depth that cannot be reached.
> Redrawn from `ReportMap`'s own labels, with depth moved to a toggle marked *not in the map
> today*, so the reason the left-tree shape was chosen is still visible without pretending it
> pays off yet. Reports flagged the vacuum; the invented columns were mine to find.

> **CORRECTED by Reports, and I had it half wrong.** I read the `array_map` callback in
> `fields`, saw it return `['to' => …]`, and concluded the edge had no name. **The name is the
> KEY:** `array_map` preserves string keys, so the payload is
> `"relations": {"customer": {"to": "customers"}}`. Verified in PHP rather than reasoned about.
> **I read what the transform RETURNED and never looked at what it was keyed by** — the name was
> in the structure, one level up from where I was reading.
>
> What is genuinely missing is a **human** label: `customer`, not "the customer on the job".
> Titleising the key works until a relation is called `owning_franchise` or `performed_by`.
>
> **Ruled: do not add one speculatively, and here is the exact trigger.** Measured, `ReportMap`
> holds **one** relation in the whole product — `jobs.customer → customers` — so *"Jobs ›
> Customers"* names the hop unambiguously and a label buys nothing. **The moment two relations
> from one resource point at the SAME target** (say `customer` and `billing_contact`, both to
> customers), the target name stops identifying the edge, the tree shows the same phrase twice,
> and a human label becomes required rather than nice. It is one field in `ReportMap` and one
> line in the controller at that point.

### 7 · Organization · Brand colour picker — **SHIPPED. Jose: desktop 1, mobile 6**

**Status corrected 2026-09-15.** He picked these numbers earlier the same day and `client/lib/widgets/brand_colour_card.dart` is 311 lines on main (`bdb7702b`). The row still said *DRAWN, with Jose*.

`docs/previews/brand-colour-picker-layouts.html`, committed `f38e797f`. Three desktop,
three mobile, plus the "not set yet" state on a toggle. UI/UX designs AND builds this one;
it lands on `organization_screen.dart` beside the logo.

**Why it is the critical path rather than a nicety.** Jose chose customer-brand-first;
Web Management built `brand_colour`, the company-then-organization resolution and
`PUT /branding/colour` (`organizations.edit`, `#rrggbb` only). But `brand_colour` has
**zero references in `client/lib`** — measured with a pattern proven to fire server-side
— so no customer can set a colour and every site renders the fallback. **The decision is
inert until this exists.** A field with no way to write its first value is the same defect
as a list with no way to add its first row.

Hence the "not set yet" state being a toggle rather than an afterthought: it is every
customer today, and it says plainly that the colour on their website is legible but not
theirs.

**Two things in the drawing that are the design, not decoration:**

- **One colour, never a pair.** The text on the button is derived, and the card names
  which it chose and at what ratio. This is why item 5's `_pair` helper must NOT be
  reused here — see the sharpened trigger above.
- **Companies inherit unless they set their own**, in the language the Widgets screen
  already uses for a site following the primary.

The seven presets were measured rather than eyeballed: each clears 4.5:1 against its own
derived text, tightest `#00838f` at 4.64:1, so a customer who picks instead of typing
cannot land on an unreadable button.

> ⚠️ **BLOCKED ON A ONE-CHARACTER FIX THAT IS NOT MINE.** The drawing derives the text
> with **pure black**; `Branding::readableOn` still returns `#111111` (checked
> 2026-09-09, unchanged since `a4e9c016`). The 0.179 threshold is the crossover for pure
> black, so with `#111111` the worst case anywhere is **4.12:1** instead of **4.58:1**,
> and a band of ordinary mid-tones lands under 4.5 — `#00838F` gives 4.18:1, which would
> have **our own default trip our own warning on a pair the customer never chose**.
> Sent to Web Management with the numbers. **Do not build this picker until it lands**,
> or the preview will disagree with the widget for exactly those brands.

### 6 · HR · HR record: Training card — **DONE, verified `539d459c`** (`hr-record--training-card`)

`docs/previews/hr-record-training-card-layouts.html`, committed `f3aaf3b7`. Three desktop,
three mobile, plus the refused state on a toggle that applies to whichever option is open.
**Jose chose desktop 3 (three counts, then the guides) and mobile 6 (a card per guide, most urgent first) on 2026-09-09.** Sent to HR to build, copying Training for the wording.

**Built by HR** (`local_4c933357-…`), whose `docs/SESSIONS.md` row names
`client/lib/screens/hr/*` — and `records_tab.dart` is in it.

> **This is a DELEGATION and it is deliberate, so it is logged rather than assumed.**
> The standing rule is that the UI/UX owner builds what Jose approves and does not hand
> it back — written after an audit of 28 galleries found the sidebar search, the account
> profile and the call-desk proportions all approved and never built, one with a release
> note claiming it had shipped. **The rot is not "someone else typed it", it is "everyone
> assumed someone else typed it".**
>
> Delegated here because HR owns the *neighbour*: the load-bearing part of this design is
> that the card must not look like their Expiring certifications, which is a relationship
> between two panels on one screen that only the owner of both can see while working. HR
> asked for it, named the file and committed to building it.
>
**Built by HR and verified against the drawing 2026-09-09** (`client/lib/screens/hr/training_card.dart`,
on main). Footnote present in the gallery's words; refusal a lock plus the server's sentence;
strip on desktop, absent on the phone; urgency ordering as specified.

**HR improved on the drawing in one place and it should not be collapsed later:** they split
`forbidden` from `!ok`, so a refusal gets the lock and a genuine failure gets `ErrorNote`. I had
only said "never an empty card". That is [[an advisory must not wear the failure component]] and
its converse — **a real failure must not be dressed as a polite note.**

**CLOSED 2026-09-09.** HR applied the ruling at `cc48b3d1`; verified at `539d459c` by reading
`_counts`, not the hand-over. Three cells partition all five — `{lapsed, notPassed}` → **"Needs a
pass"** boxed, `{notStarted, inProgress}` → **"Outstanding"**, `{passed}`. The test asserts the
exact cell contents AND that the figures sum to the row count on a fixture carrying all five
states (2 + 3 + 2 = 7), so a sixth state going uncounted fails there rather than shipping.

> **"Outstanding" is HR's word and it is better than the bucket I specified.** I named the
> grouping and left the label, which quietly made it their problem — and *"Not started"* over
> somebody on screen 3 of 9 would have been a small lie repeated on every record. Same class as
> the strip reading zero, just cheaper. **Specify the bucket AND the word, or the person who has
> to write it inherits a decision you did not know you were making.**

**Breakpoint CLOSED too** — `LayoutBuilder` on `constraints.maxWidth < 600`, verified at
`ad12d292`, with a test that places the card in a 320-point box on a 1400-point window. I
described the failure; HR built the case that reproduces it.

> **Name a widget by its FILE, not by its feature.** I told HR "the work schedule card uses
> LayoutBuilder". They grepped `work_schedule_panel.dart`, found none, and corrected me —
> correctly, for that file. The panel is the *editor*; the card is `_WorkScheduleCard` in
> `profile_screen.dart:694`. One feature, two files, and "card" versus "panel" is the whole
> difference. **A claim aimed by feature name gets checked against whichever file carries the
> name.**
>
> Their supporting claim — that the house uses of `LayoutBuilder` are two files — measured
> **43** when I took it. Worth recording as the general form: **when a check returns an absence,
> widen the search before concluding a practice is rare.** A single-file grep answers a question
> about that file, never about the codebase.
>
> **And then my 43 went stale in about twenty minutes.** HR measured 44 and was right: the
> difference is `training_card.dart` itself, which their own fix had just added. I had quoted a
> count as a standing property while the thing it counted was being changed by the conversation
> I was having. **A count is a measurement with a timestamp, not a property of the codebase** —
> and the one I used to correct someone else with was the one that decayed.

> ⚠️ **RULING, against the build: the strip must account for every row it sits above.**
> Five states exist (`lapsed`, `notPassed`, `notStarted`, `inProgress`, `passed`); the strip
> counts three, and `notPassed` — flagged `bad` — is not one of them. **A person with two failed
> tests and nothing lapsed gets a strip reading 0 · 0 · 0 above two red rows.** The strip's whole
> job is answering "is anything wrong with this person" before the detail, and there it answers
> no while the answer is yes, twice — failing hardest for the person most worth catching.
>
> **My drawing was incomplete, not HR's build.** I drew three states because the gallery's sample
> data had three. Fix: keep three cells, partition all five — `lapsed + notPassed` (boxed),
> `notStarted + inProgress`, `passed`. Rows keep their exact pills; the strip summarises.
>
> **Do not widen "Due for retaking" to cover a failed test** — "retaking" implies a pass that
> lapsed, and someone who never passed is not retaking anything. That word is Training's. Use a
> summary word for the cell ("Needs a pass"). The label is negotiable; the completeness is not.
>
> Test to add with it: assert the cells sum to the row count on a fixture carrying all five
> states, so a sixth state added later fails here instead of silently going uncounted.
>
> **Verified again 2026-09-09 after HR reported it built: still unchanged, and their message
> had crossed the ruling.** Everything else on their list measured true — key, boxed error
> cell, `narrow` branch, footnote under a divider, lock plus verbatim reason, empty sentence,
> five tests plus a caller test. **The summary was accurate and the one thing that was not
> still needed the file opened**, which is the case for measuring rather than reading a
> hand-over: a summary can only be true as of when it was written.

**Second finding, not blocking, raised with the same ruling.** The breakpoint reads
`MediaQuery.sizeOf(context).width < 600` — the WINDOW's width, not the card's. Harmless today
because the aside stacks full-width, but `TrainingCard` is a keyed widget passed into a slot,
so it is built to be placed. Put it in a side panel and MediaQuery reports the desktop width
while the card has 320 points, rendering a three-cell strip into a column that cannot hold it —
silently. **`LayoutBuilder` asks the question the layout depends on** (how much room do I have)
rather than what kind of device this is, and it is what the work schedule card uses for the
same decision.

> **UI/UX keeps the verification**, which is the half that actually answers the rule: HR
> sends a SHA, and it is checked against the drawing — the three counts, the lapsed one
> boxed in the error colour, per-guide cards in urgency order, the ownership footnote
> present, the refused state showing the server's sentence. **Not delivered until that
> check passes.** Jose can reverse the delegation in one word; he has been told. Training owns the data and the
wording; HR owns the screen and builds the card. Endpoint `GET /training/records?user=`
is live at `routes/api.php:732`, `training.monitor`-gated and bounded by reach over the
**person**, not the content — that bound was the fix for an audit finding of cross-franchise
disclosure of named people's results.

**The design problem is the neighbour, not the list.** This card lands beside HR's
*Expiring certifications*: a 60-day lookahead over licences the employee typed, **theirs**
to renew. Training's is test passes already lapsed, **ours** to re-assign. Different table,
different fact, different person responsible — and now the same screen, similar size, both
about time running out.

> That is this doc's own rule about credentials, one level up: **a screen showing one
> member of a set whose members look interchangeable is not neutral about the others.**
> Two panels that look alike get treated alike, and one of them is not the reader's job.
> So every option carries a line inside the card naming which of the two it is and who
> acts — drawn as real UI, muted and always present, never an annotation.

**Settled with Training, do not re-open:** it never says "certification" (that word is
HR's panel's, about a different table); "due for retaking" means **already lapsed**, not
lapsing soon, deliberately asymmetric with HR's lookahead so it cannot hide that the harm
has already started; and Training writes nothing into HR's tables.

**The refused state is a normal state of this card, not an error** — it shows a lock and
the server's sentence, never an empty card.

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
when a second screen NEEDS THE CHECK, not before.

> **Sharpened 2026-09-09, by the case that nearly mis-fired it.** This first read "when
> a second colour picker exists". A second colour picker now does exist — the brand
> colour picker, item 7 — and it must **not** use this helper: there, the text on the
> colour is DERIVED rather than chosen, so there is no pair a person can get wrong and
> nothing to warn about. The trigger is a second consumer of the CHECK, not a second
> screen with a colour in it. A rule worded by the surface it appeared on would have
> promoted a helper to serve a caller that should never call it.

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

### 8 · Inventory · General tab

Jose's ask, verbatim: *"I feel we are missing a General Tab where it works like a
dashboard showing a summary of everything, graphs, warnings, etc."* Asked which of
three scopes he meant — item health, money, or activity — he answered **"all of the
above."** All three ship from one endpoint; nothing is split across screens.

**The endpoint:** `GET /api/v1/inventory/summary?period=week|month|quarter|year`, live
at `4f8a9dec`. Two real payloads, captured from the actual code through fixtures (not
invented): `docs/previews/inventory-general-payload.json` — a caller **with**
`inventory.financials` — and `docs/previews/inventory-general-payload-no-financials.json`
— the same scene, **without** it. Diff the two; the difference is the design problem in
miniature: the `health.value` key is gone entirely and `no_cost_recorded` never appears
in `warnings`, and the tab still has to read as a finished screen rather than one with a
hole in it.

**Placement, already decided by Jose:** General is the **first chip** on Inventory and
**opens first**. `inventory_screen.dart`'s `_tabs` today are Stock, Items, Orders,
Locations, with Stock as the default pane — the four shift right one place, General
leads, and Stock is no longer what a user lands on.

**The three blocks.**
- `health` — stock-health figures (`items`, `low`, `out`, `locations`,
  `open_purchase_orders`, and `value` when the caller is permitted). Every figure that
  names a filtered list carries a `links` entry pointing at it; a figure with none has
  no live list behind it.
- `warnings` — a **fixed-order** array (`out_of_stock` leads whenever it fires). A
  warning with nothing in it is **omitted entirely**, never sent at a zero count — a
  row that can never fire must never render.
- `movement` — a time series: `buckets` (dates) and `lines` (`in`, `out`, `adjusted`,
  `transferred`), index-aligned to the buckets. `transferred` carries `internal: true`
  because it nets to zero org-wide — real motion between shelves, not real gain or loss.
  **Know before you draw it: a bucket is not a day.** The axis reuses the Dashboard's
  period vocabulary, so `week` gives days, `month` and `quarter` give start-of-week,
  and `year` gives months. **A month is therefore four or five points, not thirty** —
  the captured payload shows exactly two, because its fixtures span two weeks. If a
  bar-per-day is what the design wants, say so and it becomes a real decision rather
  than a surprise at build time.

**Five choices that are design's, stated as questions:**
1. How do the three blocks stack at 375px versus desktop width?
2. Is `movement` drawn as bars, lines, or stacked areas?
3. Does `transferred` appear on the chart at all, given it is net zero and flagged
   `internal: true` — plotting it risks reading as extra volume that isn't there;
   dropping it risks making transfers look like they don't happen?
4. Does `warnings` lead the screen, or sit beneath the health figures?
5. **What does the empty state offer?** When `health.empty` is `true` the server
   sends one sentence and nothing else. That fires only for a reader with **no
   items and no locations** — which is precisely somebody who has never set
   inventory up, arriving at the module's front door on their first day. A screen
   that says "No items to show." and offers no way to make the first item is the
   empty-state-with-no-entry-point trap: the tab is only ever seen working by
   people who already have data, so nobody notices the beginning is missing. The
   sentence is the server's and stays; what sits beside it is design's.

> **A note on that sentence, added 2026-09-09.** It reads "No items to show.",
> not "No items in reach." and not "No items yet." UI/UX ruled board-wide
> (`b543a45a`) that an empty sentence describes the BOX, not the world: a scoped
> reader seeing a subset cannot be told the organization has nothing. The same
> string is served by the Dashboard's Inventory box and by this endpoint on
> purpose — one sentence, one owner — so if a mockup wants different words here,
> that is a change to both surfaces and worth saying out loud.

**Constraints that are not design's to choose** (inherited from §*Inherited
constraints* above, restated here so this brief stands alone): no modals; colours from
`client/lib/core/theme.dart` only, never a literal; 44px touch targets; a figure with
no `links` entry is drawn as text, not a dead control; and the tab must look complete,
not broken, for a caller with no money on it — that is exactly what the
no-financials payload is for.

**One caveat worth stating plainly:** there is no "overdue purchase order" warning and
there cannot be one — purchase orders carry no expected-delivery date, so there is
nothing for a date to be overdue against. `purchase_order_open_a_long_time` ("open more
than 30 days") is the honest weaker claim that ships instead. Do not draw a red
"overdue" state; the server has no fact to back it.

---

### 20 · HR · Work Hours — **BRIEF, not yet drawn** (from Clock In, 2026-09-17)

Jose asked for this screen in his own words on 2026-09-17, in the Clock In
session. It names new elements (a sidebar, a search field, an expanding row),
so it is UI/UX's to draw and build. Quoted rather than paraphrased, because the
detail is his:

> * First i should only see 1 per user, as u can see for example i appear
>   multiple times as Jose Luis Demasi, instead make it so if i click the user
>   it expands and shows all the clock in and outs
> * I also want to see the amount of hours
> * overall i want a better UI Design, i want to see the profile picture on the
>   left side before the name as well
> * green clock in indicator, red clock out indicator next to the hours
> * also move the work hours tab next to dashboard so it's second in place.
> * for the ui i would like to have a left sidebar with every user, ( search bar
>   on top ) so when u click a user it shows you the history for the week, total
>   hours, days worked, avg/day, it should also always show me the week i'm in
>   from Sunday to Saturday ( so for example when i enter today, i should see the
>   record from sunday till today but nothing before sunday, ( this should be
>   modifiable for each organization so they can choose how they count their
>   days, but for now that is the default )

**Already done by Clock In at `e5c3ecff`, so do not redo it:** the rename to
**Work Hours** (`time_clock.tab`, en + es), the tab moved second in
`hr_screen.dart`, and the whole data layer. The screen currently on that tab is
still the old one-day list; replacing it is the build.

**The endpoint is `GET /api/v1/hr/time-clock/week?on=YYYY-MM-DD`** (`hr.view`
plus the organization Clock in switch; `on` is ANY date inside the week wanted,
omit for this week). It answers:

```
data: {
  week_start, week_end,        // YYYY-MM-DD, inclusive
  starts_on,                   // 'sunday' by default, org setting
  timezone, timezone_label,
  people: [{
    id, name, is_active, has_avatar,
    total_seconds, days_worked, average_seconds,
    open,                      // clocked in right now
    shifts: [{ id, date, clocked_in, clocked_out, clocked_in_at, clocked_out_at,
               duration_seconds, open, ended_by, outside_schedule, extensions }]
  }]
}
```

Five things worth knowing before drawing it:

- **Everybody in reach is in `people`**, including anyone who worked nothing
  that week (zero totals, empty `shifts`). A name missing from a sidebar reads
  as "not on the team", which is not what "did not clock in" means.
- **`clocked_in` / `clocked_out` are already formatted** in the organization's
  clock and 12/24h setting. `clocked_in_at` / `clocked_out_at` are the UTC
  instants if you need them. Do not format from the device.
- **An open shift reports the time worked SO FAR** in `duration_seconds`, not
  null, because it is summed into the total shown beside it. `open: true` says
  it is still running — that is the green indicator Jose asks for.
- **`has_avatar`** says whether to fetch `GET /api/v1/users/{id}/avatar`; fall
  back to initials rather than requesting one that is not there.
- **Copy already exists** as `time_clock.total`, `time_clock.days_worked`,
  `time_clock.average_per_day`, `time_clock.search_people`,
  `time_clock.no_hours`, `time_clock.this_week` (en + es, migration
  `2026_09_17_100000_work_hours_copy.php`). Ask Clock In for more rather than
  hardcoding English.

Week navigation is not in Jose's list. The tab it replaces has day arrows, so
dropping the ability to look back at all would lose something he has today —
worth one of the options showing how you would move between weeks.

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
  **Guide player 4 is superseded (2026-09-30):** Jose told Training to read guides
  the way A1 Tools shows them ("the ui, everything"); the reader is A1's outline and
  topic layout since `ca601d43`. Do not rebuild layout 4.
- **Web Management · SEO panel** — two-pane list/detail, 288/900, shortcode as a copy
  **button** rather than helper text, setup blocks above the fields when a site is
  unconnected and below once connected. Shipped in 0.0.0118. The last two generalise
  and are worth stealing: a value whose only purpose is to be pasted gets a copy
  button, and position can carry "is this what you should be doing right now".

### 10 · Dashboard charts · where the colours come from — **SETTLED, shipped `d212d2dd`**

`docs/previews/dashboard-chart-palette-layouts.html` @ `b4bfb612`. Three desktop, three
mobile, with a **slice-count slider and a light/dark toggle** — the toggle because the worst
defect exists only on light, and an option looked at in one theme hides half the problem.

Dashboard (`local_0417a3f0`) reported the cycling: `_palette` returns six swatches,
`dashboard_chart.dart` indexes them `colors[i % 6]` in the wedge painter (`:309`, `:345`),
the series painter (`:395`) and the legend (`:124`), and nothing caps slice count.
`jobs.statuses` is a `TYPE_LIST` setting with **no maximum**, so a seventh status repeats the
first status's colour. All true. They deliberately did not fix it, because fixing it means
choosing colours — correct, and the colour-literal ratchet is exactly why.

**Measured the six before drawing anything, and the report understates it by five slices.**

| | light | dark |
|---|---|---|
| primary | `#00708F` | `#00AEEF` |
| secondary | **`#00708F`** | `#0093CA` |
| tertiary | `#41627A` | `#6E8CA0` |
| primaryContainer | `#C6E7FF` | `#004C6B` |
| secondaryContainer | `#D2E5F4` | `#374955` |
| tertiaryContainer | **`#E8DDFF`** | `#4A4263` |

**On light, swatch 1 and swatch 2 are the same colour.** `theme.dart` pins `secondary` to
`_cyanDeep` on purpose, so a widget reaching for it gets something in the brand's range —
which is right for every widget in the product except this one. The chart is the only place
that reads the six roles as six *different* colours. The default `jobs.statuses` is four, so
**every light-theme jobs pie on every dashboard today draws Scheduled and In progress
identically.** Not an edge case at seven; the shipped state at two.

**And swatch six is the violet.** `#E8DDFF` is the hue `theme.dart`'s own docblock says was
banished after the owner called it *"the mark of AI did this"*. The `copyWith` there pins
`secondary` and `tertiary` but not their **containers**, so the generated hue survives in the
container. `leads_screen.dart:1293` refuses it by name — *"tertiaryContainer is off limits: it
would be the first purple in the app"* — and the chart palette reaches for it at six slices.
Measured: those are the **only two** references to `tertiaryContainer` in `client/lib`, so the
rule was known, written down, and leaked in exactly one place.

> **The ratchet was pointed at hex literals in widget files. This one arrived through a scheme
> role**, which passes every check a hex literal fails. A colour the app never chose can enter
> through a name the app did choose.

**Every option must answer what RUNNING OUT looks like** — Dashboard's framing and the better
half of the report. *"A seventh slice drawn in a repeat colour looks exactly like a correct
chart"*: the cycling is not the defect, the **silence** is. Same shape as the empty box that
showed one sentence of three for three weeks.

**Three findings, three reaches — Dashboard asked that they not be flattened, and they are
right.** The duplicate cyan is **live now** at two slices on the four-status default. The violet
is swatch index 5, so it needs a **sixth** slice to draw at all — latent on Jobs, ordinary on
Inspection (a bar per definition name) and Email (one per mailbox). The cycling needs a
**seventh**. One alarm covering all three is how the live one stayed invisible.

> **A constraint any option must respect, measured by Dashboard and recorded here because a
> future gallery will want to break it.** `presentation` is the glance's contract *and the
> reader's choice within it*: the glance declares `PRESENTATIONS` and `DEFAULT_PRESENTATION`,
> the menu offers exactly `allowed_presentations`, the server validates on read
> (`DashboardController:64`) and on write (`:286`), and the header icon is drawn from the
> **stored** value. **So the client may not substitute a chart kind.** A phone quietly drawing a
> pie as bars yields a pie icon, "Pie" ticked in the menu, and bars in the body — the defect
> Dashboard removed in `c4254b03`, where the menu offered a kind the server refused. Option 4
> was drawn that way first and now shows the selection instead. Two honest routes if Jose picks
> it: the glance declares `bar` in its allowed set, or the client changes the *selection* on a
> narrow viewport and saves it. **Whether a saved choice should follow the window is a product
> question and goes to Jose, not into a gallery note.**

That is also the trap inside the most obvious answer. **Option 1, a wider brand ramp, is the
one that looks finished and is not** — it raises the ceiling to ten and says nothing at eleven.
The gallery says so underneath it rather than letting it read as the safe pick. Options 2, 3, 4
and 6 each make running out into something on the screen: a named and counted wedge, one
neutral for the tail, or a chart type that needs no palette at all.

**Jose answered in a DIRECTION, not a number (2026-09-11):** *"a more diverse palette with
colors that dont blend into one another like, red blue green yellow, high contrast types."*
Dashboard shipped it the same minute — eight categorical hues per mode (blue, orange, aqua,
yellow, magenta, green, violet, red), **validated rather than picked**: lightness, chroma, CVD
separation, normal-vision floor and contrast against this app's real surfaces, on the adjacent
pairlist. Worst adjacent CVD ΔE 9.1 light / 8.4 dark under protanopia and deuteranopia at
severity 1.0 — red beside green is the classic failure and these clear it, which matters because
his four named colours are exactly the pairing that usually breaks.

**My three desktop options are history and he was right to overrule the shape of them.** All
three kept series identity inside *brand* identity — a ramp of cyans and slates. Those are two
different jobs, and conflating them is what produced the bug in the first place. The record is
kept in the gallery rather than deleted, because what was chosen over what is the useful part.

**Two things that outlived the decision.**

> **The legend is now load-bearing for accessibility, not decoration.** Three light steps sit
> below 3:1 on white, which the standard permits only with *relief*: identity must not be colour
> alone. The relief is a labelled dot per slice plus a semantics label naming every slice with
> its total. **Mobile 6 is therefore withdrawn as drawn** — it hid rows to save space. Any future
> chart layout may collapse the COLOUR of a tail and never its naming.

> **Running out has a standard now, not a taste call: a ninth series is never a generated hue.**
> Past the eighth slot the tail is a neutral derived from the scheme. I have raised one finding
> against it — at ten slices that produces *two* grey wedges and two identically-coloured legend
> dots against two different labels, which is verbatim the defect that opened this item. A grey
> wedge honestly says "not an identity"; a legend ROW still promises a mapping it cannot keep.
> My call: one wedge and one row labelled with its count, or keep the wedges and collapse the
> legend to a single row. Both separate is the one option that is not available. With Dashboard.

### 11 · Email states · one vocabulary for two modules — **RULE SET, no screens yet**

SES production access granted 2026-09-14, so Automations and HR are both about to wire sending.
Two modules building email screens at the same time is how a product ends up with two words for
one idea, so the vocabulary is decided before either draws anything. Sent to both sessions
verbatim; **no screens are queued and none are drawn.**

**Measured 2026-09-14, not assumed: EzBizi cannot observe email delivery.** No SNS route in
`routes/api.php`, no bounce or complaint handler, and the `delivered_at` columns a grep turns up
are the audit outbox (`create_audit_events_table`) and the workflow event bus
(`create_workflow_tables:133`) — neither is email. The two observable facts are **handed to SES**
and **SES refused it at handoff**.

> **Never print a claim the app cannot observe.** "Delivered" is a sentence nothing in the
> codebase can support. If SES event destinations are ever wired this changes — and it must
> change *visibly*, by a screen gaining a state, not by a word quietly becoming true.

The words:

- **"Sent"** — the handoff. True, plain, and never paired with a promise of arrival.
- **"Couldn't send"** — refused at handoff, and it must say WHICH: not an address, unverified
  sending identity, rate limit. That is the half somebody can act on.
- **Nothing** between them. A "Pending" for a handoff that already completed is a spinner that
  never resolves.

**Automations has one extra state and HR does not.** An automation action can be *queued* long
before it is attempted, and that IS observable — its own event bus carries `available_at`,
`attempts` and `failed_at`. Worth drawing, and it must be named for what it is: **waiting to
run, not waiting to arrive.** The two look identical on screen and mean opposite things to
somebody working out why a customer never heard from them.

**A FOURTH STATE, found by Automations verifying the rule rather than taking it.** `MailStatus`
knows whether this installation delivers **at all** — `NON_DELIVERING = ['log','array','null']`,
and `missing()` names the credential when the transport is `ses` with an empty key. Its own
docblock is the argument: *"`MAIL_MAILER=log` accepts every message and returns success... which
is how somebody ends up waiting for a password reset that was never going anywhere."*

So a handoff can succeed while nothing leaves the building, and **"Sent" would then be a
confident sentence naming the wrong outcome** — this rule finding its own fourth case rather than
an exception to it. Rare in production, routine on a demo or staging box, which is exactly where
somebody tests a rule, reads "Sent", and concludes the feature works.

> **Two different things, and they do not go in the same place.** *Did this message leave* is
> per-message and belongs on the row. *This installation is not sending mail* is ONE fact about
> the box, and printing it per row is repeating it N times.

**~~HR has already solved the per-message half~~ — I wrote that here, and it was wrong. HR caught
it and gave the praise back.** Left visible rather than edited away, because how it was wrong is
the useful part.

`HrInvitationsController::deliver()` **only caught transports that THROW.** `log`, `array` and
`null` throw nothing, so it returned `true` and the panel said *"The invitation has been emailed
to them"* on exactly the demo and staging boxes where somebody tests invitations and concludes
the flow works. `ses` with a blank credential is the same shape. Fixed on main at `8a0ef426`:
`MailStatus::delivers()` is asked **after** the handoff, so the message still reaches whatever
transport is configured — observable to a `log` reader and to `Notification::fake` — and only the
CLAIM depends on whether that transport goes anywhere. No new state on screen: a non-delivering
box lands in "Couldn't send", which was already the right wording for it.

> **How I got it wrong is this entry's own rule, turned on me. I read the docblock and reported
> it as behaviour.** That method's comment is genuinely excellent and states the right rule — it
> simply overclaimed about the present. **A docblock is a DESCRIBER**, and entry 11 exists
> because a describer reads from a different source than the thing it describes: the code's
> source is the code, and a comment's source is what its author believed while writing it.
> I spent this week telling two sessions to check the icon against the drawing, then took a
> comment as evidence about a method.

**And the belief was written down twice, which is why nothing caught it.** HR's own test asserted
`emailed: true` under phpunit's `array` mailer, with the comment *"so this one genuinely sends"*.
A test carrying the same wrong premise as the code cannot fail on it.

**The line I praised is still worth the praise, and is still true.** *"The panel used to assert
'nothing has been emailed' unconditionally, which was true while nothing could send and became a
lie the day something could."* **That is this entry's rule having already gone wrong once, in the
opposite direction.** A sentence does not have to be edited to become false; the world can edit
it — and the method underneath that sentence had quietly become false the same way.

**~~The gap, measured: the installation-level fact reaches EzBizi staff and nobody else.~~
CLOSED by Jose, 2026-09-14, and his answer was a question: *"why would an installation not send
mail im confused"*.**

He was right to be confused, and the confusion is the answer. **It is not a customer state.**
`MailStatus::delivers()` is false for a `log`, `array` or `null` mailer, or `ses` with a blank
credential — every one of which is a development or staging box. EzBizi is one production
installation with SES configured, so **no administrator will ever see this**, and an
administrator-facing surface for it would be chrome built for a state that cannot occur.

**What it was worth is entirely internal, and that half stays.** The reason it surfaced at all
is that *we* test invitations on exactly those boxes, and were being told "Sent" when nothing
left — the defect HR fixed at `8a0ef426`. The per-message truth matters; the banner does not.
`screens/staff/health_screen.dart:261` remains the right and only home for the installation-level
fact, because staff are the only people who can be on such a box.

> **The generalisable half: a state reachable only in development is worth a TRUTHFUL message and
> never a designed surface.** Drawing chrome for it spends product space on a condition no
> customer can reach, and — worse — makes the next person believe it is a thing that happens.

**The precedent, from Email the same day.** An empty state read *"an inbox added on another
company stays there, switch to that company"* — a correct sentence about the wrong problem. The
eight people who saw it had a permission problem, the inboxes were already in their view, and
the only person who could fix it never sees that screen. **A confident sentence naming the wrong
cause is worse than a vague one**, because it sends somebody hunting. Email's fix was to publish
which of three reasons applies rather than assuming one, and that is the shape every failure
state here should take.

### 12 · HR · the invitation panel, and the two failures that read the same — **PICKED: 2 and 5**

`docs/previews/invitation-sent-states-layouts.html`. Three desktop, three mobile, with a **state
switcher** — the two failures are the whole reason it is drawn, and an option looked at only in
its success state hides the problem.

HR brought states rather than a layout, which is the ask working. The panel is
`client/lib/screens/hr/invitations_tab.dart:495`, shown the one moment the raw token exists,
switching on a server `emailed` bool.

> **REVISED 2026-09-14, and the revision removes a state I drew.** HR measured what the server
> can actually prove and answered the question I asked them — are there more than two remedies?
> **Two are knowable, and the third is not knowable at handoff at all.**
> `MailStatus::delivers()` is reliable: it answers before anything is attempted and `missing()`
> names the credential. A thrown handoff is reliable *that it failed* and that is where
> reliability stops. **The invitation is a PLATFORM send** — `Notification::route`
> (`HrInvitationsController:446`) rather than `MailDispatcher::asTenant` — so it never produces
> `CannotSendMail`, the class carrying a human sentence, which is referenced only by
> `MailDispatcher`. What it gets is a raw Symfony transport exception, and splitting "address
> refused" from "transport down" means **string-matching a vendor's error text**.
>
> **So "address refused" is no longer drawn as a send state.** The failure mode of guessing wrong
> is telling somebody to fix a perfectly good address — a confident sentence naming the wrong
> cause, which is the defect this entry exists to avoid. I drew it anyway, and HR caught it.
>
> **The design answer is better than the state was.** The address is now correctable in EVERY
> state, including success, because HR's other correction stands regardless of diagnosis: the
> invitation keeps that address on record, so the next invite goes to the same typo. An
> always-available fix does not depend on knowing why anything failed. *"This address is wrong"*
> gets said once there is a typed bounce event to say it with — which is what makes option 2
> stronger rather than weaker: the strip carries three states today and gains `bounced` later,
> and the one state whose evidence was fragile is never drawn at all.

**The gap as I first stated it — that the REMEDY differs and the sentence does not — was right
about the problem and wrong about the split:** Today a failure reads *"The email could not be sent, so nothing has reached them. Copy the
link and get it to them yourself."*

- **Transport down** — copy the link, hand it over, and the invitation is perfectly good.
- **Address refused** — handing the link over still works, *and* the invitation now carries a
  typo nobody has corrected, so the same person is invited to the same wrong address next week.

Same sentence today, two different actions — **but only one of the two causes is provable.** The
first is, and gets its own state. The second is not, and gets an always-available fix instead of
a diagnosis. The response still needs more than a bool, so this remains a server change of HR's
as well as a screen; it just carries *which of the provable states*, never a parsed cause.

> **In every failing case the address IS the problem, and today it is the panel SUBTITLE** — the
> one thing on screen that cannot be acted on. That observation is what separates the three
> desktop options: fold the reason into the sentence (1), put it in a status strip (2), or
> promote the address to the subject of the panel (3).

> **And the fix is a REISSUE, not a field edit — which changes the label, not the layout.**
> HR measured the surface: four routes (`routes/api.php:927-933` — index, store, resend, destroy)
> and **no PATCH**, so correcting an address is new server work whichever option wins. The shape
> matters more than the cost. **If the typo'd address belongs to a real person, that person is
> holding a working invitation to somebody else's organization.** Repointing the row at the
> corrected address leaves their link alive while the screen says the problem is fixed — and that
> is worst in the SUCCESS state, which is exactly where an always-available correction is most
> useful. The semantics already exist and are tested: `resend` mints a new token and kills the
> old one (`hr_screen_test`: *"resending mints a new link, and says the old one is dead"*).
>
> So the control is labelled **"Re-invite at a different address"** with *"The current link stops
> working"* under it, in all six options, from one definition. **"Fix the address" was a label
> describing a field edit attached to an act that revokes and reissues** — a describer
> contradicting the described, which is the rule I spent this week giving other people.

**Designed knowing a state is coming.** HR measured that SES event ingestion is being built —
the SNS subscription is currently EMAIL protocol to a human mailbox, so events reach a person and
not the app, and HTTPS ingestion into a table keyed by recipient and message id is in progress.
So **delivered and bounced become knowable soon**, and per entry 11's own rule that must arrive
as a screen gaining a state rather than a word quietly becoming true. Every option is drawn with
a place to put `bounced`; option 2's is the cleanest seam, because the strip changes and the body
does not.

**One asymmetry worth knowing before reading the options.** The account suppression list means
the FIRST send to a typo bounces silently and a SECOND send to the same address is refused at
handoff. So a resend already produces "Couldn't send" correctly today — **only the first send is
blind**, which is exactly the send where somebody is standing in front of this panel.

**Two judgements HR asked for, given here so they are not re-litigated:**

- **The `null` state survives the rule.** *"Copy the link and get it to them yourself if you are
  not certain the email reached them."* It is a server older than the field, it claims neither
  outcome, and it says what to do. That is the rule obeyed, not bent — and it is deliberately not
  drawn in red.
- **"Has been emailed to them" should become "Sent to them."** HR flagged it themselves. It is a
  handoff claim wearing arrival's clothes, and it is the sentence entry 11 exists to prevent.

### 13 · Developer · switch between desktop and mobile view — **BUILT: 1 and 4** (`4b6ee87b`)

`docs/previews/mobile-view-switch-layouts.html`. Jose, 2026-09-14: *"can we add a developer only
icon on the top bar next to the phone icon, that let's u switch between desktop view and mobile
view?"*

Three desktop (how you get in) and three mobile (how you know you are in it and get back out).
Placement is settled by the ask — after `AvailabilityButton` in the bar's `actions:`
(`main.dart:1019`), which is the phone icon he means. Gate is `me.development != null`, the same
one the Development rail uses (§4.2).

> **The work is not the icon. It is that the app decides its shape in nine places.**
> `main.dart:692` is `width >= 900` and is read a dozen times — but **nine reads of the viewport
> width live across five files at three breakpoints**: 900 in the shell, the staff shell and Web
> widgets; 600 in `lead_thread`; 480 in `auth_shell`; and `side_panel` clamping itself to the
> window. A switch that flips the shell's own boolean produces **a narrow shell with desktop
> screens inside it** — wrong in exactly the places somebody would be using it to check.
> **Override the VIEWPORT, not the flag**, and all nine follow for free with no screen knowing
> this control exists.

**The design problem is not "how do I get to mobile view", it is "how do I know I am in it".** A
developer who forgets reports a layout defect that is not one. So every option is judged on how
unmistakable the state is, and each one borrows something different to say it:

- **Desktop 1** borrows nothing and says the least — mobile view is indistinguishable from a
  narrow window, with a filled icon as the only tell.
- **Desktop 2** is unmistakable, and is the only option where the viewport is a *known* size, so
  "it breaks at 390" reproduces between two people.
- **Desktop 3** shows both layouts live, which is what checking a change actually wants — at the
  price of two live copies of the app, two sets of screen state, two `initState` fetches, and any
  timer or socket a screen holds running twice. **Cheap to look at, expensive to be correct.**
- **Mobile 4** spends bar room that was bought deliberately: that bar overflowed by **266px**
  (measured 2026-09-10) with both conditional chips showing, which is why the brand is dropped,
  the punch control is icon-only and the theme cycle is gone on a phone. And it costs it *only
  for staff* — the hardest overflow to notice, because the people who see it are not the people
  who report it.
- **Mobile 5** reuses the bar's existing `bottom:` strip (`_BarStates`, `main.dart:1578`) where
  states that are not controls already live — at the price of shortening the viewport somebody is
  using to check whether content fits.
- **Mobile 6** borrows nothing from the app and renders the product at its true size, which is
  the argument for it; it covers content, which is the argument against.

**Session state, not a preference.** It is a mode you are in for a minute, and saving it to an
account would mean signing in on a desktop to a mobile layout with no memory of asking for one.

> **What it is NOT, in the words of the session that owns the real device problems.** Phone
> (`local_d95f72da`), on taking it up: *"I will not treat it as a device — Android's Telecom
> behaviour, CallKit, and push are none of them a viewport question, and the bug I shipped this
> afternoon was invisible at every width. For layout it saves me a resize; for anything about
> being a phone it proves nothing."*
>
> Recorded here rather than in a code comment because **the risk with this control is somebody
> treating a 390px column as a device and concluding from it** — and that warning carries more
> from the session that owns Telecom and push than from the one that built the column.

### 14 · Where the version lives — **BUILT** (mobile 1 + desktop 5)

`docs/previews/version-on-a-phone-layouts.html`. Jose, 2026-09-14: *"on phone i noticed there's
nowhere you can see the current version"*. He is right, measured.

`BuildStamp` has **two** call sites: `main.dart:2384`, the foot of the sidebar, and
`auth_shell.dart` (271, 332), the sign-in screen. A phone has no sidebar and you are signed in,
so neither is reachable. **Its own docblock says it sits in *"the two places somebody already is
when they wonder whether the app has updated"*** — true when written, false the day the phone
layout shipped. The fourth true-when-written sentence this week, and the first one Jose found
before any of us did.

**A second gap, found looking for the first.** The stamp is drawn `if (expanded)`, so a
**collapsed desktop sidebar** has nowhere either. That is why 4, 5 and 6 exist; without it this
would be a phone-only question and the "three desktop options" half of the standing rule would be
padding.

> **The existing comment is the strongest argument in the file and options 4 and 5 disagree about
> it.** *"At 80px it would truncate, and half a build id is worse than none — it invites people to
> compare the wrong characters and conclude they are up to date when they are not."* 4 respects
> it and does nothing. 5 argues that `201` is not *half* of `0.0.0201` — it is the only part that
> ever changes and the only part anybody compares. That is the whole judgement, and it is his.

**What separates the three mobile options is where the cost falls, not where the number goes.**

- **1** puts it at the foot of the drawer — the same widget in the same conceptual place, one
  line — and the drawer is a scrolling list of up to twenty-one modules plus two groups, so the
  foot is *below all of them*. On desktop the sidebar is as tall as the window and the stamp is
  free; in a drawer it is a place you only arrive at deliberately.
- **2** puts it under the wordmark, visible the instant the drawer opens. On a phone the brand is
  dropped from the top bar (Jose, 2026-09-10), so the drawer header is the only place the product
  names itself — and a version belongs with the name. It is also in the way of the thing the
  drawer was opened for, every time, for a fact wanted twice a month.
- **3** puts it in Your account, following **his own precedent**: the theme cycle was removed from
  the phone bar and left to Your account, so *"things about the app rather than about the work
  live in Your account on a phone"* is an established rule rather than one I invented. It is the
  only option with room for the long form and for *"What's been built"* as a named row rather
  than a tap-target nobody knows is tappable — **a stamp in a drawer looks like a label.**

**If 6 is picked, keep the sidebar stamp too.** A version is a fact rather than a control, and two
places showing one fact is only a problem when they can disagree.

**Jose picked 1 and 5, and moved something while he was there (2026-09-14):** *"i dont like the
rearrange being on the top, perhaps move it next to the version number instead."* Both built the
same day.

**Desktop 5 turned out not to need the truncation it was drawn with.** I drew `201` — the last
segment — against the existing objection that *"half a build id is worse than none."* Measured:
`BuildInfo.short` is `v0.0.0201 · a1b2c3d`, and it is the **hash** that will not fit. The version
alone is eight characters and fits at 80px with room. So nothing is truncated: **a whole field is
dropped rather than the end of one**, and the field dropped is the one nobody compares by eye.
The objection is answered rather than argued with.

> **And "next to the version" is stacked rather than side by side, because measured it could not
> be a row.** `BuildStamp` is **160px** wide on a desktop platform — the version plus the update
> control — and this sidebar drags down to 180. A `Row` gives its non-flex children unbounded
> width, so the two overflowed by **86px at the default 220**, not at some extreme. I tried
> `Flexible` first and it still overflowed; I tried an icon-only button and it still overflowed.
> Only then did I measure the widget instead of estimating it, which is the step that should have
> come first and would have saved two wrong fixes.
>
> Stacked, the control keeps its **word** — which an icon squeezed beside a 160px stamp could
> not have — and fits at every width from 180 to 420.

**The drawer's Rearrange moved too**, from an icon in the header to the labelled control at the
foot beside the version. He complained about the sidebar, but the drawer's was equally "on the
top", and after mobile 1 there is a version at the foot of both to sit next to. Applied to both
surfaces deliberately; if he meant desktop only, that is one line back.

### 15 · Mail health for an administrator — **DESKTOP 2 + MOBILE 5, one state still blocked**

`docs/previews/mail-health-for-an-administrator-layouts.html`. Jose said yes to the question
raised in entry 11: *"1 - yes add a health status"*. HR brought states rather than a layout, which
is the ask working, and named the thing the design turns on.

**Two facts that must not become one sentence.** (A) the platform transport is not sending —
nothing reaches anybody, and the administrator **cannot** fix it. (B) this organization has no
verified sending identity — their own authored mail refuses while EzBizi's invitations and resets
keep working, and the remedy **is theirs**. One sentence covering both sends somebody to fix the
wrong thing, which is entry 11's own finding.

> **⚠ THE BLOCK BELOW IS A DATED RECORD, NOT A DESCRIPTION OF THE PRESENT (marked 2026-09-14).**
> Every measurement in it was true when taken and **none of it is true now.** Jose: *"I HAVE
> ANSWERED 4 TIMES ALREADY, SO PLEASE TALK TO EACH OTHER, GET RID OF IT."* The card, the panel and
> its 21 tests went in `b32fc797`; the four routes, the controller, `DomainVerification`,
> `DnsVerifier` and the `mail.verification` config block went in `474fd1e6`. Re-measured here
> rather than taken from the relay: the panel file is gone, `mail/sending-identity` matches zero
> lines in `routes/api.php`, and `MailStatus::summary()` no longer returns `tenant_domains` — with
> a control on `delivers` to prove the grep fires.
>
> **Kept rather than rewritten.** A block recording what was measured and when is worth more than
> one silently updated, and SES deliberately did not edit it. What it now records is that a design
> brief can be correct, cited, agreed by two sessions, and describe nothing, within six hours.
>
> **What survives is the FINDING, which was never about the card:** two facts that must not become
> one sentence. What is gone is the surface B was going to hang on. See the note after this entry
> for where that leaves it.

> **MEASURED BEFORE DRAWING, AND IT CHANGED THE BRIEF: B already has a home.**
> `client/lib/widgets/sending_identity_panel.dart` is a card in the Settings screen today, with
> the whole verification flow behind it and four routes live under `mail/sending-identity`. Its
> own copy already draws HR's line — **in its second sentence. Its first sentence is false**, and
> HR found that by measuring the very quote I had offered as evidence.
>
> The card says *"Newsletters and anything else your own people write go out from this address"*,
> and **a newsletter never touches the sending identity at all**: `NewsletterSenders::for()` has
> one arm, Mailchimp, and that client uses the tenant's own API key, so the from address is
> whatever their Mailchimp account sends as. Measured callers of `asTenant`: `ActionRunner:473`,
> `SendSavedReport:171`, `InspectionReportMail`.
>
> **So the card's headline example is the one thing verifying a domain does not change.** Somebody
> does DNS work to fix their newsletters and nothing about their newsletters changes — a remedy
> that does not remedy, arriving through an EXAMPLE rather than through the state sentence. It is
> the shape this whole entry is about, one layer below where either of us was looking, and it was
> found because a quote used as evidence got checked rather than repeated.
>
> So this is **not a new four-state panel**. B is a STATE that card gains; anywhere else gives
> one fact two voices that can disagree. **A is the gap**, and the options differ on where it
> goes — because "EzBizi is not delivering anything" is not a setting, and does not belong in a
> settings card about your own address.

**I nearly reported the opposite, twice.** My first grep used `sending-identities` (plural) and
found no routes; my second searched the client for the class name and found no screen. Both were
my search term, not the codebase — the route is `mail/sending-identity`, singular. **Both were
caught by running a positive control** on a term I knew was present. Had either gone out, HR and
SES would have been told to build a screen and an API that already exist.

**Nothing in any option shows `missing`.** `MailStatus::summary()` carries it and it is a list of
environment variable names — `AWS_ACCESS_KEY_ID`. Right for staff, wrong for a customer: EzBizi's
infrastructure, a wire name on screen, and useless to somebody who cannot act on it. HR flagged
it; it is the constraint that rules out "the staff panel behind a different permission".

**Option 3 is drawn to be rejected, deliberately rather than never.** One card with one badge is
the obvious design and the fastest to scan — and its badge has to say one word about two facts
with two owners and two remedies. "Not sending" covers both and names neither. That is the merge
this entry exists to avoid, arriving through the badge rather than through the sentence.

> **DECIDED 2026-09-14: desktop 2, mobile 5** — Jose, against a numbered list: *"1 - as you
> recommend"*. A rides above the screen, where somebody wondering why a password reset never
> arrived will meet it; B is a state of the sending-identity card, which already owns who your
> mail comes from. Build is SES's (`MODULE-OWNERS:151` gives them Mail), not HR's.
>
> **Desktop 3 was drawn in order to be rejected and he rejected it.** Worth recording that the
> tactic worked: the obvious design got looked at and turned down deliberately, rather than
> arriving in six months as somebody's simplification.

> **AND ONE STATE IS NOT YET WRITEABLE. The layout is approved; B's SENTENCE is not.**
> SES put a question to Jose that he has not answered — whether his ruling is about the FROM
> LINE or about what EzBizi's mail service may be used for at all. It decides which sentence B
> carries:
>
> - **From line:** their authored mail goes out through EzBizi's service showing EzBizi as the
>   sender until a domain is verified. B's remedy is *"verify a domain"*, and the card is exactly
>   where it belongs.
> - **Use at all:** a tenant may not send authored mail through EzBizi's service, verified or
>   not. Then B is not a setting with a remedy — it is *"this cannot send yet, and it is not
>   something you can turn on"*, and a **"Verify a domain ›" link on that card would be a remedy
>   that does not remedy**, which is the exact defect this entry already corrected once.
>
> **ANSWERED the same day, and it is the second option: use at all.** Jose, plainly and with
> visible impatience at being asked again: *"Email should not be sent as EzBizi, EZBIZI EMAIL IS
> ONLY FOR EZBIZI ACTIONS LIKE ACCOUNT RECOVERY, PASSWORD, RESET, VERIFICATIONS, ETC."*
>
> So **"verify a domain" is not B's remedy** — verifying one does not make a tenant's own mail
> EzBizi's to carry. A `Verify a domain ›` link on that card would be **a remedy that does not
> remedy: the same defect this entry corrected in the same card's note three hours earlier.** It
> came that close to shipping twice in one afternoon, through two different doors.
>
> B is now drawn as informational, with the tenant's own provider as the way out. **And B may not
> be a health state at all** — a rule about what a service is *for* is not a fault, and a status
> panel is the wrong shape for it. Left drawn rather than deleted because Jose approved a layout
> containing it; whether it survives is the mail owner's call.
>
> **The consequence that outlives this entry, measured and handed over:** `asTenant` is called by
> `ActionRunner:473`, `SendSavedReport:171` and `InspectionReportMail` — three paths sending
> tenant-authored mail through EzBizi's transport, on the wrong side of the ruling. That is
> `MODULE-OWNERS:151`'s to settle, not something to redesign around quietly. See
> `ezbizi-never-sends-newsletters-itself`, now the whole-category rule rather than a newsletter
> one.

> **WHERE B STANDS NOW, 2026-09-14 evening.** The remedy sentence is no longer "verify a domain"
> — that flow is retired — and is not "connect a provider" either. `79b5e142` replaced it with
> **the tenant's own SMTP**, so it is *"this business needs its own mail server connected"*.
>
> **And I have not established what that sentence points AT, so the gallery does not claim one.**
> `79b5e142` is server-only — `MailDispatcher`, `MailTransport`, `TenantSmtpHost` and two test
> files, no client. `TenantSmtpHost` is an SSRF guard whose own docblock says it exists to stop
> somebody mapping the private network *"from a settings form"*, so a form is intended; the Email
> module already has one for mailbox SMTP (`screens/email/mailbox_panels.dart`). **Whether B
> points at that or at a form not yet built is SES's to answer**, and inferring it from a class
> name is the exact mistake recorded in `a-docblock-is-a-describer` — a method name is a describer
> too.
>
> **A is unaffected by any of this** and is still desktop 2 / mobile 5. Build A; B waits on one
> sentence from the mail owner, not on a drawing.
>
> **Correction to a measurement offered while answering that question, recorded because acting on
> it could break something.** SES reported that the mailbox form's SMTP fields are never used —
> *"NOTHING SENDS THROUGH IT... no outbound path in that module at all."* Measured: there is one.
> `app/Support/Email/ImapReplySender.php` speaks SMTP with a host, user name and password
> (`EHLO`, `AUTH`), its own docblock names the SMTP host as *"a second field the tenant types"*,
> and it is reached from `ImapMailboxReader`, which `MailboxesController` uses. The Email module
> also draws `compose_panel.dart`, `reply_panel.dart` and *"Reply as {address}"*. `smtp_host`
> appears in **three** files — the controller and two client files — and **not** in the `Mailbox`
> model, with a control confirming the grep fires.
>
> **Their CONCLUSION still stands and the gallery does not change**: B points at no screen. But
> *"nothing sends through it"* would justify treating those credentials as dead, and deleting or
> ignoring them breaks replying from an IMAP mailbox. The fork they named is if anything sharper —
> the credentials are per-mailbox and already spoken for, so *"which mailbox sends the report"* is
> a real question rather than a naming one.
>
> **One thing spotted while re-measuring, and handed over rather than fixed:**
> `client/lib/screens/settings_screen.dart:90` still points at the deleted file — *"These three
> lived in sending_identity_panel.dart, whose own docblock called…"* — citing a docblock nobody
> can now read. Past tense, so arguably a deliberate history note; but it sends a reader to a file
> that does not exist, which is [[the-stale-comment-sits-above-the-rewrite]] in its clearest form.
> Not my file.

**Day one is state B for everybody.** The desk measured production at 2 organizations and 0
sending identities (relayed through HR, not measured by me). If that holds, the day the refusal
ships every organization is in B with a perfectly healthy platform — so a design treating B as the
unusual case is wrong for everyone at once.

### 16 · A mail server for sending — **SHIPPED** (`d2a256e1`)

> **SAID "SES building" UNTIL 2026-09-17.** `widgets/sending_server_panel.dart` exists and
> `settings_screen.dart:655` mounts it behind its own search match. A status naming what
> another session is doing is the fastest-decaying kind in this file: the session that
> could correct it has no reason to read an entry filed under somebody else's name.

Relayed by the SES session 2026-09-15, verbatim, with the question it answered:

> **SES:** *"Layout numbers — `docs/previews/sending-server-layouts.html`, UI/UX recommends 2
> and 5. Now the only thing between this and a screen people can use."*
> **Jose:** *"1 - was you recommend"*

Read as *"as you recommend"*. It resolves to **2 and 5** under either parse, because the
question named the numbers and SES's own sentence offered to build to the recommendation
anyway — so there is no reading that lands somewhere else. Recorded rather than paraphrased,
because a pronoun resolved against whatever the reader was holding is how yesterday went.

> **SCOPE, AGREED 2026-09-15: seven states now, three later, and the drawing already said so.**
> SES is building `nothing connected · set here, typed · set here, copied · inherited ·
> inherited-and-that-one-was-copied · copied-then-that-mailbox-changed · saved, never tried`,
> and **not** `refused (5xx) · unreachable · not ready (4xx)`. Nothing sends yet
> (`throughTenantProvider` is still on `Mail::build`; the move to the Email module's socket is
> with Email), so there is no reply code to sort and those three would be **the painted half of
> a feature, green against fixtures nobody could produce.** That is the same boundary the
> gallery header already draws — *"drawn so the screen is honest once there is something to be
> honest about; it is not evidence that anybody will ever be told."* Agreeing costs nothing it
> did not already cost.
>
> **The trap in a half-built state list is not the missing states — it is `saved, never tried`.**
> It is true of every row today and becomes **false the moment the first send happens**, and the
> change that makes it false is **in another module**. Nothing in SES's code has to be touched
> for that screen to start lying: a row that has been tried, and failed, will go on saying
> nobody has tried it. Flagged to SES to leave the guard where the next person looks and to tell
> Email, since Email owns the event that invalidates it.
>
> **RESOLVED 2026-09-15, and better than what I asked for — `970d416d`, verified.** SES did not
> add a guard, they removed the need for one: `last_attempted_at`, nullable, written by
> `noteAttempt()` on **every** attempt *before* the outcome is known (recording only successes
> would leave a row that has failed ten times still reporting nobody has tried it — the same lie
> with extra steps). The sentence is now **derived rather than described**, so it stops being
> true on its own the first time anything sends. Nobody has to remember, and Email does not need
> to know that shipping their socket changes the meaning of a string on somebody else's screen.
>
> **Two failures found inside the derivation, and both are ones my own advice walks people into.**
> A missing column reads as null, so `never_tried` answers true whether or not the migration ran
> — the honest-looking assertion passes against a build with **no column at all**; the test that
> can distinguish is the one that records an attempt and requires the answer to *change*. It then
> went red for a different reason: the resolver cached rows and invalidated only on writes **it**
> made, so a read after `noteAttempt()` served the pre-attempt row — `never_tried` true for a
> server that had been tried, one layer above the column that exists to prevent exactly that.
> **A derived sentence is only as fresh as the read under it.**
>
> **Copy agreed**, carried until the three failure states land and removed when they do:
> *"This screen shows what is set up and when it was last tried — not whether it worked. A failed
> send is not reported here yet."* Its removal condition is mechanical rather than a promise: the
> same column that makes the sentence stop lying is the one that makes the failure states
> renderable.
>
> **CLOSED `f8963636`.** The admission is now **derived** rather than removed by hand: it renders
> only while the payload carries no outcome (`last_result`, a name anchored to the
> `last_attempted_at` that already exists rather than invented). Whoever adds the first failure
> state deletes the sentence by adding it. **Pinned both ways** — present in every state the card
> can render, and gone the moment an outcome arrives; without the second half a card that always
> renders it passes every entry of the first, which is the shape that suite had that morning. The
> change immediately reddened a fixture keyed `last_result: 'marvellous'` — named for an unknown
> field and asserting the opposite once that field was claimed. A note could not have found that.
> Residual gap, written down at the test: a differently-named field is the old hole again. Client
> 1826 green.
>
> **NARROWED AGAIN, 2026-09-15: four states, not seven — and the copy flow is parked.** SES read
> the drawing against their own storage rather than against my description of it, which is the
> instruction I gave them, and found three states that cannot be drawn truthfully:
> `copied`, `inherited-and-copied`, `copied-then-that-mailbox-changed`. Building:
> `nothing connected · set here, typed · inherited · saved, never tried`.
>
> Three reasons, stacking. **The facts are not stored** — `connectServer` writes host, port,
> username, password, encryption and nothing else; I verified that in the source rather than
> taking it, and the copy line would have to invent a mailbox, a date and an actor. **The
> stale-copy line is not a missing column but a missing relationship** — comparing a credential
> against the mailbox it came from needs a recorded source, and with none there is nothing to
> compare against, so it cannot be derived at any cost. **And there is nothing to copy from:** a
> deploy-desk measurement SES relays says 6 production mailboxes, 0 with a mail server, 0 with
> credentials, all `gmail_workspace`, which carries no SMTP host — so the affordance would open
> onto an empty list for every business that exists. *(That last figure is relayed through two
> sessions and I did not measure it.)*
>
> **My own note argued the opposite and I reversed it.** It said the second fact row earns its
> rent because the common state costs one line of *"Typed here"* while the rare state fails
> silently. **That argument assumed the row sometimes says something else.** With copying parked
> it cannot — and nothing records that a value was *typed* either, so a constant "Typed here"
> is an assumption rendered as a fact on every identity forever, which is precisely what a
> derived sentence exists to replace. **The row is now conditional rather than constant**
> (`31086edf`) and renders nowhere today; switching the gallery to a copied state shows it
> return. Verified by exercising it: two rows in the typed state, three in the copied one, in
> both picked layouts.
>
> The two-labelled-facts shape itself is unchanged and is what gets built. It stops paying rent
> while it has nothing to say.
>
> **BUILT `d2a256e1` — `client/lib/widgets/sending_server_panel.dart`. One finding, and it is in
> MY drawing rather than their build.** The third rung of the cascade renders **`EzBizi default`**
> when nothing is set at any scope, directly above *"Nothing is connected, so these are not going
> out."*
>
> **The sentence is true — I verified it rather than assuming the worse case.** `asTenant()` is
> retired and refuses unconditionally (Jose, 2026-09-14) and `throughTenantProvider()` throws when
> the scope has no provider connected, so a tenant's report does **not** quietly leave as EzBizi.
>
> **The word above it is the problem.** `PlatformIdentity` calls itself *"the root of the cascade,
> not a fallback beside it"* and the resolver's loop does end there — so "EzBizi default" is
> correct about the cascade and wrong about this card. For every other setting it means *a value
> applies and it is ours*; for this one it means *no value can apply to your content at all*.
> Same words, opposite operational meaning, one line above a sentence that contradicts them — and
> the misreading it invites is the one Jose has corrected **four times**. My own gallery header
> argues against precisely this (*"never an implicit fallback, because with one nobody can tell by
> looking which credential is actually in use"*) and then I wrote the implicit fallback into the
> card.
>
> **Proposed: keep `Set here` and `Inherited from {name}` verbatim; the third rung becomes
> `Not set anywhere`.** It diverges from the settings screen's dialect deliberately — the two
> facts stay distinct, which is what that screen's docblock actually asks for, and the third stops
> claiming a fallback this content cannot use. SES's call if they would rather vocabulary
> consistency won.

`docs/previews/sending-server-layouts.html`. The screen entry 15's state B has been pointing at
with no destination. The fork landed: SES and Email settled that **a report must not go out
through a mailbox's reply credentials**, so the sending identity keeps its own.

**Shape, from SES:** a business connects its own server — host, port, username, password,
encryption — stored on the sending identity, scoped organization / company / franchise. The
convenience is an **explicit** "use the same server as info@" that **copies** the values and says
so. **Never an implicit fallback**, and their reason is a UI reason as much as a technical one:
with a fallback nobody can tell by looking which credential is in use.

> **TWO FACTS, INDEPENDENT, AND THAT IS THE WHOLE DESIGN PROBLEM.**
>
> - **Whose value is this** — the cascade's question, which this screen **already has words for**
>   and which I took rather than reinvented: `Set here`, `Inherited from {name}`, `EzBizi
>   default`. `settings_screen.dart:105` warns that *"the third is not a rounding of the second"*,
>   and its own comment records why the wording is central: *"a card saying 'inherited'
>   differently from the rows beside it is the confusion this screen exists to prevent."*
> - **Where did these values come from** — typed, or copied from a mailbox.
>
> **A franchise can inherit a value that was copied**, so one status line cannot carry both
> without merging them — the defect entries 11, 12 and 15 were each about. The three desktop
> options differ only in how they keep the two apart.

**The state to switch to first is "copied, then that mailbox changed".** A copy does not follow
its source — that is the point of copying rather than linking — so the screen has to say so
rather than implying a live connection. It is also the state a tap can hide, which is why mobile
6 promotes it out of the tap and says in its own note that this is an admission the pattern does
not hold for the one case that needs it.

> **⚠ OPEN, AND NAMED SO THE DRAWING DOES NOT IMPLY IT IS CLOSED.** SES flagged that the sixth
> state is a functional problem wearing display clothes. A copied password that no longer
> authenticates does not degrade — **the send FAILS, on a schedule, to somebody expecting a
> report.** The screen saying *"still sending on September's password"* is necessary and not
> sufficient: **something has to notice, and nothing does today.**
>
> They asked for it to sit here as an open thing rather than be implied fixed by the state
> existing, and that is the right instinct — **a state drawn for a condition nothing detects is a
> screen that will be honest the day somebody makes it possible, and silent until then.**
> Detection is theirs. **Where a noticed failure surfaces is mine, and I will draw it when there
> is a signal to draw against** rather than inventing states the server cannot prove — the
> discipline entry 12 arrived at the hard way.
>
> It is also entry 15's state A one layer down: a message that reaches nobody, with nobody told.

> **SIGNAL ARRIVED, AND FOUR STATES ADDED THAT ARE MEASURED RATHER THAN INVENTED.** I asked SES
> what the server can *prove* before drawing any failure state — the discipline entry 12 reached
> the hard way. The answer carried a condition that settled their transport choice:
> `Mail::build()` surfaces one `TransportException` with the SMTP code inside its **message**, so
> classifying it means parsing an error string; the Email module's own socket reads the numeric
> reply codes and already sorts them. They are building on the socket. (Their other reason:
> Symfony re-resolves the hostname, so that path carries a DNS-rebinding gap the socket does not.)
>
> **Four, not the three I asked about, and the split that earns its place is 4xx against 5xx.**
> A 5xx will not change and sends somebody to the account; **a 4xx is "not now" and must not** —
> a temporary refusal that tells you to check your password is the confident-wrong-cause defect
> this queue keeps circling, and Email's socket already split them for that reason. "Never tried"
> is a timestamp with no inference in it and is the state every row is in the moment it is saved.
>
> **A precedent Email owns, that SES asked to be held to, and that I am recording so it survives
> both of us.** Their enum refuses to invent `PasswordRejected` for IMAP, because on that path
> there is no authentication attempt to classify — *"a wrong password and a mail server that has
> moved are, from here, identical."* **A state is available when the server can distinguish it,
> not when the screen would like it.**
>
> **Still not drawn: where a failing scheduled report gets NOTICED.** This screen tells somebody
> already looking, and nobody looks at it. That is a separate surface and it is not being stacked
> on top of an unpicked gallery.
>
> **AND IT PARTLY SHIPPED ALREADY, AS A PRECEDENT TO AVOID.** SES measured it rather than
> accepting my "not yet built", and they were right to — verified here end to end:
> `AutomationEngine:177` passes `$e->getMessage()` into `Automation::noteFailure()`, which
> counts consecutive failures and at the limit writes `disabled_at` + `disabled_reason`; the
> controller returns that field; `automations_screen.dart:243` reads it and **`:370` draws it in
> an `ErrorNote` on a customer's card**.
>
> **So a raw PHP exception message is shipped, today, to a customer** — the thing `MailboxFault`'s
> docblock forbids by name: *"the provider's own text is not a cause and must never be what
> somebody reads."* Automations owns the file and has been told by SES; the replacement shape is
> mine and is in their inbox: **our sentence for what happened, when, and what to do — with the
> exception kept where staff can read it.**
>
> **And `noteFailure()` notifies nobody.** It sets a flag, saves, and the card is the only place
> it surfaces. So *"this screen tells somebody already looking at it, and nobody looks at it"* is
> not a hypothesis about an unbuilt surface — **it is the shipped behaviour of a built one**, and
> the automation is already stopped by the time the card says so.

> **ONE RULE FOR WHOEVER DRAWS A STATUS SURFACE NEXT, because both of us got it wrong in the same
> direction.** *"Never tried"* is a timestamp, not a health state. I would have drawn it red; SES
> would have shipped it as a state the server computes. **Treating an absence as a finding** is
> the same move as a `null` cascade source rendered as a value somebody chose — and
> `settings_screen.dart` already warns about that one: *"the third is not a rounding of the
> second."* Nothing has happened yet is not a verdict.

**Recommendation: 2 and 5.** The common state is one server typed once and never touched again,
and it costs a row saying "Typed here"; the rare state is the one that goes wrong silently.

**Not blocked by, and must not wait for:** which identity a recipient should SEE a report come
from is still with Jose via the deploy desk. That decides copy on the report side, not this
screen. SES promises no date — the screen is theirs and next.

### 17 · An automation stopped itself, and nobody was told — **DESKTOP 2 BUILT (`832709d2`); 5 came free**

`docs/previews/automation-stopped-itself-layouts.html`. Automations asked for these after their
own fix to entry 16's note: `noteFailure()` counts consecutive failures, disables the rule, **and
notifies nobody**. The card is the only place it surfaces, and it is a card somebody opens only
if they already suspect. A thing a business relies on stops, and the way they find out is that a
customer did not get something.

> **THE SPINE: a notification is a MOMENT, a stopped automation is a CONDITION.** A bell row is
> read once and cleared; the rule is still off. Anything that only announces is the wrong shape
> for something that persists until somebody acts. So the question each option answers
> differently is **what stays on screen for as long as the rule is off** — and the "Turned back
> on" state is the one to check, because a condition display has to *clear itself* rather than be
> dismissed.

**MEASURED, and it bounds what each option may claim.** Automations: `automation_runs` keeps the
thrown text and a status per run, but `consecutive_failures` is a **live counter, not a history**.
So *"it failed 5 times in a row"* is provable **at the moment of disabling** and cannot be
reconstructed afterwards. A sentence composed later may honestly say *when* it stopped and *what
the last error was*, and may not mention the run — unless it was written at disable time and
stored. **Each option says only what its own moment can support**, which is why the bell row is
the wordiest and the glance the quietest.

> **AND THE AUDIENCE QUESTION IS NOT INDEPENDENT OF THE SURFACE, which is worth knowing while it
> sits with Jose.** A bell notification is **per person** and cannot be written until somebody
> decides who — owner, franchise admins, or whoever last edited the rule — and
> `NotificationDispatcher::audienceFor(null)` falling through to every active user makes a wrong
> answer worse than the gap. **A glance and a badge are per SCOPE and need no addressee at all.**
> So **2 and 3 are buildable today and 1 is not**, and Automations are not blocked unless they
> pick 1.

> **BUILT, and mobile 5 needed no build at all — measured after the fact rather than assumed.**
> Automations shipped desktop 2 at `832709d2`. I then checked whether the phone needed its own
> version: `dashboard_screen.dart` lays glances out in a `Wrap` with **no width branch and no
> `MediaQuery` read anywhere in the file**. So the same glance reflows to one column on a phone.
> **Mobile 5 was never a second build — it is the same payload at a narrower width**, and the
> sentence they wrote is already the short one the drawing called for, because the glance carries
> no date (the organization's date format lives in the client, so a stamp composed in PHP would
> be written in the wrong one).
>
> **The split is what unblocked it, and it was two questions wearing one coat.** *Who hears it* is
> per person and still with Jose; *where the condition lives* is per scope and needed no
> addressee. Automations verified the per-scope half rather than taking it — `AutomationsGlance`
> is organization-scoped with no franchise filter, stated in the `Glance` interface docblock.
> **They had been treating the audience problem as blocking the surface, and it only blocks one
> surface.**
>
> **The "Turned back on" state is why the sentence is derived rather than stored.**
> `AutomationsController:741` nulls `disabled_at` on re-enable, so a sentence computed from that
> column lasts exactly as long as the condition and clears itself. **Had that column not been
> cleared, the same sentence would have been a permanent false alarm on the dashboard** — the
> opposite of what it was drawn for. There is a test asserting the clause disappears.
>
> **Still open:** the bell notification (blocked on Jose's audience answer) and desktop 3's
> sidebar count, which pairs with 2 rather than competing.

**Why 2 was the recommendation.** The dashboard is the one screen people open without being sent
there, a glance persists exactly as long as the condition and clears itself, and it needs nobody
to have decided the audience first. **3 pairs well with 2** — the glance is where you find out,
the badge is what keeps it in view while you do not act on it. **6 is drawn to be judged rather
than assumed**: a dot on a phone's drawer icon says *something, somewhere* and cannot say what.

### SETTLED RULE · A colour earns a name at the first DISAGREEMENT, not the second site

Written 2026-09-22 after `theme_conventions_test` went red at 36 literals against a baseline of 31.
Reading what they were is the whole argument:

    hr/work_hours_tab       0xFF1A7F37   on the clock
    email/settings_tab      0xFF188038   already named `okColor` in the file
    widgets/incoming_call   0xFF1B873F   the Accept button

**Three sessions independently needed "this is working" and each wrote a different green**, because
`ColorScheme` hands out `error` and nothing for its opposite. That is a missing role, and it is now
`StateColour` in `theme.dart` with exactly two members — `on` and `caution` — whose light values
are the ones already in the tree rather than new ones.

**The rule the three of us converged on, and it cuts both ways:**

> A role with ONE member is a slot that describes one widget rather than an idea. A role with THREE
> spellings is a name that was needed and never given.

**The first draft of this rule said "the second SITE", and Email corrected it with my own case:
that is not what happened here.** There were already three sites and no name, and the thing that
forced it was that the three spellings **disagreed**. So:

> **The trigger is the first DISAGREEMENT, not the count.** Two sites sharing one literal are
> invisible and fine — nothing is going wrong yet. Two that spell the same idea differently are
> loud, because the disagreement is the proof that the idea exists and has no home. One site cannot
> disagree with anything, which is why a lone literal never qualifies however obviously
> "semantic" it looks.

Applied literally, "second site" would mint a role the moment a second widget copies the first
one's hex — which is the case that least needs one. **The corrected rule yields the same three
answers as the decisions already taken below**, which is the evidence it describes what was done
rather than a new policy invented afterwards.

Phone's words for the first half, declining a slot for their own button. Two live applications:

- **The Decline red in `incoming_call_popup` stays a literal.** Phone went looking for its role and
  found the better question: there are **six call-ending controls in the product and theirs is the
  only painted one** (`call_tab:983`, `:1494`, `:2125`, the bar's Decline and Hang up, and the
  pop-up's Ignore). So the question is not which role the red is, it is why one of six is painted
  — and the answer holds: the pop-up is the only surface where both choices are symmetric and
  demanded at once, so the colour does the work of a two-second glance. **If a second call-ending
  control is ever painted, that is the second site, and it wants one deliberate pass over all six
  rather than a colour for whichever was touched most recently.**
- **The verified-sender blue in `email/message_list` stays a literal.** One site, and "do we trust
  this claim" is a different idea from "what state is this thing in". If Phone or Leads ever draws
  *this number is verified*, that is the second site and the slot pays for itself.

> **AND THE COLOUR WAS THE SMALLER HALF OF THAT ONE.** `_VerifiedDot` drew its ring with
> `Colors.white` — a white halo on the dark theme's surface, whatever the theme said. Found by
> Email while answering a question about drift. **A single instance never shows up as drift,
> because a ratchet counts instances**: the count was right and the screen was wrong. Fixed to read
> `colorScheme.surface`; the check inside stays white because it sits on the blue, which does not
> change with the theme.

**Why a counting ratchet beat a rule.** A rule saying "read colours from the theme" had been in
`DEVELOPMENT-RULES.md` the whole time and three sessions wrote literals anyway. A number that went
up could only be answered — Phone's sentence: *a rule would have been argued with.*

### 157 · Reports workspace (A1 Leads takeover row 10) — **sent 2026-10-29 — Jose: "As u recommend" (1 and 4)** (`docs/previews/reports-workspace-layouts.html`)

Reports brief: report picker, place, dates, preview, PDF/Excel, draft→approved→sent, Send to WhatsApp + scheduled rules. 1 = list + preview + actions column (recommended); 2 = tabs; 3 = steps; phones 4-6. Reports builds.

### 156 · Leads › Manage › Connections (all lead sources) — **sent 2026-10-29 — Jose: "as u recommend on everything" (1 and 4)** (`docs/previews/leads-connections-sources-layouts.html`)

Via Google API / Leads: Website gets a real sites pane; WhatsApp, Facebook/Messenger, Instagram and GBP (no leads since Google ended chat in 2024) join the list. 1 = extended source list + table + side panel (recommended); 2 = card grid; 3 = one table; phones 4-6.

### 155 · Scheduling › Settings › Technicians (native roster) — **sent 2026-10-27** (`docs/previews/scheduling-technicians-layouts.html`)

Jose 10-27 "EzBizi is the master" via Scheduling and the desk: native roster on ad56e9f0e replaces Settings › Schedule Workiz linking. 1 = table + side-panel editor (recommended); 2 = cards by branch; 3 = areas × technicians grid; phones 4-6.

### 154 · Customer Experience: media, services, review stats, phone check, other sources, WhatsApp link — **sent 2026-10-27 — Jose: "yes go ahead with all ur recommendations" (1, 4, 7, 10, 13 and 16)** (`docs/previews/customer-experience-layouts.html`)

Customer Experience asked for 3 options each for six screens replacing Rodrigo's system, numbered 1–18. Recommended: 1 = media grid with type chips and Copy to locations in a SidePanel; 4 = category chips over grouped services rows, Copy with a per-listing diff; 7 = stats strip, histogram and gauge beside the reply queue with Draft all and the send window in place; 10 = one row per listing comparing Business Profile, RingCentral and policy numbers with a match chip; 13 = sources table (Google, eKomi, Thumbtack) plus a funnel card; 16 = WhatsApp row in each listing's Info tab with Copy and QR. One phone per recommended option.

### 153 · One shared Book panel (all of Workiz New Job) — **sent 2026-10-27 — Jose: "yes go ahead with all ur recommendations" (1 and 4)** (`docs/previews/book-job-panel-layouts.html`)

Jose via the desk and Jobs: one Book panel with every Workiz New Job option, opened from Leads (replaces book_lead_panel), Jobs, Customers and Communication with prefill; saves EzBizi then Workiz. 1 = side panel, one scroll, sticky Book (recommended); 2 = 4-step wizard; 3 = full page two columns; phones 4-6. Jobs builds.

### 152 · Task Board: left sidebar of boards + number formatting — **sent 2026-10-27 — Jose: "Go ahead" (1 and 4)** (`docs/previews/task-board-sidebar-layouts.html`)

Jose 10-27 via Task Board: "add the left navigation sidebar as well, people would obviously only see what they have access to, like monday has" and "add format to the numbers automatically". 1 = collapsible tree replacing Other boards (recommended), 2 = icon rail, 3 = boards home page; phones 4-6. Number formatting is in 1 (no options needed).

### 151 · Email › Settings made compact — **sent 2026-10-27 — Jose: "Looks better" (1, 4 and 7)** (`docs/previews/email-settings-layouts.html`)

Jose: "we can make an improvement to the Email - Settings tab in terms of UI." **1** (recommended) summary line (N Workspaces · N inboxes · N need attention); Workspaces as compact rows (domain, company, inbox count, one health chip; expand for the 4 permissions, auto-expanded only when missing; Disconnect under ⋯); Connected inboxes table (address, company, kind, status, last checked, roles; search + company filter; row opens a side pane); Add an inbox top right. **2** nested by Workspace. **3** compact cards. Phone **4** / **5** / **6**. **7** (Jose: "same with the google workspace tab") Workspace tab as the same compact rows: people, signature progress, status chip, the next step on the row.

### 150 · Monitoring › Lighting (RGB controller) — **sent 2026-10-26 — Jose: "Looks good" (1 and 4)** (`docs/previews/monitoring-lighting-layouts.html`)

Monitoring relayed Jose ("EzBizi also act as a RGB Controller … in bulk or individually"). **1** (recommended) a Lighting tab (lighting.manage): bulk bar (All PCs in view / ticked N, mode Off/Static/Breathing/Rainbow, swatches + hex, brightness, Apply with result); list (tick, PC + person, live swatch of current mode, lights count, set by, status chip incl. engine missing / no lights / needs admin / close <brand app>); per-PC side pane with the same controls + lights found; search + status filter. **2** a Lighting section inside PC Specs. **3** rooms/zones. Phone **4** / **5** / **6**.

### 149 · Fleet Management › General dashboard — **sent 2026-10-26 — Jose: "looks good" (1 and 4)** (`docs/previews/fleet-dashboard-layouts.html`)

Jose: "this dashboard can be improved." **1** (recommended) two columns: fleet status as one stacked bar (active / in shop / out of service, owned · rented); Needs attention rows each with its fix (Add insurance, Assign keys, Book service), no duplicates; Coming up next 30 days (registration, inspection, insurance, service; overdue red); Spending by period vs previous, bars by category, top vehicles, empty state with Record a cost. **2** four tiles + attention. **3** vehicle list with health lights. Phone **4** / **5** / **6**.

### 148 · Services › Catalogue with photos — **sent 2026-10-26 — Jose: "as u recommend" (1 and 4)** (`docs/previews/services-catalogue-layouts.html`)

Jose: "can we improve this screen, have it show the images as well too" (services already carry photo). **1** (recommended) photo grid grouped by kind/tag with counts, price on the photo, name/number/tags/company, placeholder + Add a photo; search, kind chips, sort, remembered Grid/List toggle; click opens the editor; 5 per row down to 2. **2** list + thumbnail. **3** big gallery. Phone **4** / **5** / **6**.

### 147 · Scheduling › Booked vs available + earlier-opening offers — **sent 2026-10-26 — no answer in 10 min, went with 1 and 4** (`docs/previews/scheduling-booked-vs-available-layouts.html`)

Scheduling relayed Jose and Shoval (server d9efd6504). **1** (recommended) day picker (tomorrow default) + search + summary (over / open spots / full); one row per area (Company · Branch) with a booked-vs-capacity bar (overflow red), count, chip, time-window blocks; over first; row opens its jobs; "Offer openings" panel under the row: open windows, later-booked candidates ticked up to max_sends, message preview, Send with per-job results; send hours respected. **2** week heat map. **3** cards. Phone **4** / **5** / **6**.

### 146 · Work Hours › Paid Time Off › Requests — **sent 2026-10-26 — Jose: "looks good" (1 and 4)** (`docs/previews/pto-requests-layouts.html`)

HR relayed Jose (staff request PTO, approvers decide). **1** (recommended) a Balances · Requests segment inside Paid Time Off with a waiting count; Waiting for you (approvers): person, grouped days with ½, total, note, "Approving leaves N", Approve / Deny with an inline required reason; Request time off inline: the Record-days-off calendar, balance before/after, note, Send (blocked with a sentence when over the balance unless allowed); My requests with status chips and Cancel while waiting; search + status filter. **2** own tab. **3** per-person panel. Phone **4** / **5** / **6**.

### 145 · Customer Experience › Reviews as an inbox — **sent 2026-10-26 — Jose: "Looks good" (1 and 4)** (`docs/previews/reviews-inbox-layouts.html`)

Jose: "I feel this screen can look better." **1** (recommended) summary strip (average rating vs last month, new this month, unanswered, 1-2 star to answer; each filters); search + quick chips (Unanswered, 1-2 stars, This week) + More filters; list (avatar, name, stars red when low, unanswered dot, location tag, time, one line; match shown only when useful); right pane (full review, match/link, technician named, reply box with Draft with AI + Post, notes, status). Board stays a toggle. **2** card grid. **3** cleaned table. Phone **4** / **5** / **6**.

### 144 · Project Management overhaul 2: one frame, the flow, a Rules tab — **sent 2026-10-23 — no answer in 10 min, went with 1 and 4; built 64cd5555a** (`docs/previews/pm-overhaul-2-layouts.html`)

Jose: "impossible to understand … do an overhaul" + a Rules tab. Audit: scratchpad/pm_audit/AUDIT.md (6 contradictions sent to Jobs; Action 1 is OFF in production). **1** (recommended) one frame on all tabs (purpose sentence + How this is measured, scope bar, filter bar in fixed order); stages as one ordered flow with one colour each; ⓘ on every number; tabs Overview · Jobs · Assignments · Repair team (Reports+Table merged) · Rules (new, live, read-only, links to Settings/Automations) · Logs · Settings; Needs attention cards. **2** keep the 7 tabs + frame + Rules. **3** guided home + More. Phone **4** / **5** / **6**.

### 143 · Project Management › Assignments board — **sent 2026-10-22** (`docs/previews/pm-assignments-layouts.html`)

Shoval via Jose: a Task-Board-like tab where a job lands once its deposit is paid and gets assigned to a technician. **1** (recommended) columns: "Just paid · to assign" (auto, newest first, days since payment vs limit) then one per technician + External company with load (count, $ due); drag or Assign ▾ sets the technician (EzBizi + Workiz when connected), logged; card = job, customer, area, deposit, due, stage chip, flags; search, area picker, Jobs filters. **2** stage columns. **3** queue table. Phone **4** (recommended: one column at a time, Assign button) / **5** / **6**. **Jose 2026-10-22 corrected:** it "refers to the action 1 stuff, when the job gets a payment and switches status tags" — the tab embeds the Task Board cards Action 1 creates (that board/group, Technician column), not a new assignment system.

### 142 · Project Management › Settings made orderly — **sent 2026-10-22 — no answer in 10 min, went with 1 and 4** (`docs/previews/pm-settings-layouts.html`)

Jose: "this is a mess, like, be orderly please, we need alignments, formatting, colors". **1** (recommended) cards per topic with the inherit state once in each card header; Stages as an aligned table (Stage with colour dot shared with Dashboard/Jobs | Statuses chips + Add | Scheduled Any/Yes/No | Money due Any/Yes/No); then two-column cards: Review reasons, Redo and dispute words, Alerts (label column + fixed narrow number fields with units), Estimate clock. **2** stage list + detail. **3** grid rows only. Phone **4** (recommended: stage cards) / **5** / **6**.

### 141 · Project Management overhaul (Dashboard · Reports · Table · Jobs · Logs · Settings) — **sent 2026-10-22 — no answer in 10 min, went with 1 and 4** (`docs/previews/project-management-overhaul-layouts.html`)

From Jose's file (C:\Users\jose\Desktop\Project Management Overhaul.md) via the desk + his answers. **1** (recommended) Dashboard: service-area chips + range + search; tiles in pipeline order (Waiting review with reason bar, In progress us/external + 2nd visit + redo/new, Ready to schedule, Scheduled, Done waiting payment total vs due, Pipeline $, Avg production cost, Stopped payments); waiting review by location × reason bars; waiting-to-start countdown bars (red after 10 days, daily email); jobs per team. Reports: weekly Repair Team report per company and week (draft/approved, Copy for WhatsApp, Approve), a card per tech + combined, cost from gross red when high; avg production cost report. Table: tech × week grid, missing fields amber with a count and "only rows with gaps". Jobs: stage tabs with counts (5 ‖ Completed, Cancelled), filters (area chips, 2nd visit, external, redo/one go, disputes, stopped payments), search, after-payment countdown column; job panel edits review reason / external company / 2nd visit. Settings: stage mapping, review reasons, warning days + per-company email, stopped-payment days, cost threshold. **2** pipeline board. **3** report list. Phone **4** (recommended) / **5** / **6**.

### 140 · Work Hours › Paid Time Off person panel overhaul — **sent 2026-10-22 — no answer in 10 min, went with 1 and 4** (`docs/previews/pto-person-panel-layouts.html`)

Jose: Expired always shown (no layout jump); "When they can be used"; a more colourful reset (bars/countdown); past resets; changes as a timeline; role filter; record which days were used with a date picker (scattered days). **1** (recommended) four fixed tiles (grey zeros, Left red at 0, Expired amber > 0); reset card with countdown ring + period bar (last reset → next, today, used blue, would-expire amber) + past resets chips; "When they can be used" beside; "Record days off" inline editor: month calendar multi-select (tap = day, again = half, again = clear), already-recorded days tinted, running total and what it leaves, reason, Save; "Correct the total instead" link; one timeline (days off with dates, corrections, resets with expired, start). List gains a Role picker. **2** year strip. **3** fixes only. Phone **4** (recommended) / **5** / **6**. Server needs: used days with dates (+ half days), past resets with expired counts, role on rows.

### 139 · Project Management › Logs — **sent 2026-10-22 — no answer in 10 min, went with 1 and 4** (`docs/previews/project-management-logs-layouts.html`)

Jobs relayed Jose (84be725a0). **1** (recommended) range control as Dashboard, branch + rule pickers, outcome segment Changes (succeeded + manual, default) / Failed / Skipped / All with counts; rows when, job # (opens timeline), customer, what changed, by (rule link or person), outcome chip; failed rows show the error beneath; skipped folded into one grey line per rule "skipped N times · Why?" with reasons grouped and counted. **2** every run a row, skipped off by default. **3** grouped by rule. Phone **4** (recommended) / **5** / **6**.

### 138 · Leads › Manage › Office hours — **sent 2026-10-22 — Jose: "better, go ahead, and places that do not have time set up should be highlighted" (1 and 4)** (`docs/previews/leads-office-hours-layouts.html`)

Google API session asked (9f578924e; nobody had set hours, every place ran Mon–Fri 8–5 ET). **1** (recommended) a table of places (org, companies, branches indented) with an hours summary, time zone, inherited values in grey italic "from X", an "own" chip; a yellow banner counting places on the default; Edit opens the week inline under its row (Jose: "not sure i like the sidepart", redrawn 2026-10-22): seven day boxes with open/close or Closed, Copy Monday to weekdays, time zone picker, "Use X's hours/zone again"; "Used by N auto-replies" with rule links. **2** week grid. **3** cards. Phone **4** (recommended: nested cards, edit full screen) / **5** / **6**.

### 137 · Email › Inbox like Gmail — **sent 2026-10-22 — no answer in 10 min, went with 1 and 4** (`docs/previews/email-inbox-gmail-layouts.html`)

Jose: "overhaul the Email - Inbox UI, something more akin to how gmail looks". Also fixes seen in his screenshot: one email to two inboxes shows twice, snippets show &lt; / &#39; entities, the not-connected notice shows on All inboxes, 65 inboxes in one flat list. **1** (recommended) Gmail desktop: rail with Compose, Inbox/Starred/Snoozed/Sent/Drafts/All mail with counts, inboxes grouped by company (collapsible, unread counts, Not connected group last); rounded search; Primary / Leads & customers / Notifications tabs; one-line rows (tick, star, sender + thread count, inbox tag, subject + grey snippet, time; bold unread; hover Archive/Read/Snooze); one row per thread across inboxes; reading pane right (or below/off) with Reply/Reply all/Forward and the from-inbox, customer/lead link; entities decoded; bulk Archive/Mark read/Move. **2** no pane, open over the list. **3** today cleaned up. Phone **4** (recommended: Gmail app layout) / **5** / **6**.

### 136 · Work Hours › Daily approval — **sent 2026-10-22 — no answer in 10 min, went with 1 and 4** (`docs/previews/work-hours-daily-approval-layouts.html`)

Clock In asked (Shoval via Jose; Ohio GM approves technicians' hours daily). **1** (recommended) one day (yesterday default, ‹ › + picker), branch picker, summary Waiting/Approved/No-shows, "Approve all N waiting"; table: person+role, shifts text, a day bar with the schedule dashed, worked vs scheduled, Late/Early chips, status (Waiting / Approved by X · T / Adjusted), Adjust + Approve; no clock-in on a scheduled day = "Didn't clock in"; Adjust side pane edits in/out per shift, reason required, original kept and shown struck through with who/why; adjusting does not approve. **2** cards. **3** team timeline. Phone **4** (recommended: cards with Adjust/Approve, Approve all) / **5** / **6**.

### 135 · HR › Payroll tab + onboarding Employer/Employee tabs — **sent 2026-10-22 — no answer in 10 min, went with 1, 4 and 7** (`docs/previews/hr-payroll-layouts.html`)

HR asked (server 3bd574538). **1** (recommended) Jose's columns (Employee, Salary amount+period+since, W2 Signed/Not signed + download, Start, Terminated, Role, Contract + status + download); filters W2 segment, Role, Contract, search, "Show people who left" (left ones last, dimmed); Salary opens a side pane: new amount, period, basis, effective date, note → adds to history (listed); no hr.pay.view = "—", no hr.pay.edit = read-only. **2** inline cell edit. **3** cards. Phone **4** (recommended: cards + filters, person full screen) / **5** / **6**. **7** onboarding form: Employer · N left | Employee · N left tabs, opens on the reader's side; "either" questions on the reader's own tab marked "either of you can answer"; the other side read-only with "Waiting on <name>".

### 134 · Email › Google Workspace tab (signatures) — **sent 2026-10-22 — no answer in 10 min, went with 1 and 4** (`docs/previews/email-google-workspace-layouts.html`)

Email asked (API 84231a7b). **1** (recommended) Workspace cards (domain, where connected, people count, signature status; one Workspace opens directly); inside: signatures_reason banner, template editor with placeholder chips + live preview for a chosen person, people table (tick, name/address, title, EzBizi person picker with "Same email · Link", signature Template/Own/Not set, last set; suspended dimmed), bulk bar "N selected · Set signature" with per-address results; person side pane: in Gmail now vs will be, Use the template / Give them their own, Set it now. **2** stacked sections. **3** card then wizard. Phone **4** (recommended: people as cards, long-press select, panes full screen) / **5** / **6**.

### 133 · Automations › Then step — **sent 2026-10-22 — no answer in 10 min, went with 1 and 4** (`docs/previews/automations-then-step-layouts.html`)

Jose: "how is the user supposed to even know the fields at all"; audit of every action × trigger in scratchpad/automation_actions_audit.md (forwarded to Automations). **1** (recommended) step catalogue grouped For this <record> / Send a message / Tell the team / Elsewhere, only steps that fit the trigger, not-ready ones dimmed with the reason + Set it up; Update a detail = detail picker in plain words then a value control by kind (org statuses, date, dollars, switch, tag chips); Change the status gets its status picker; message placeholders as plain-named chips with a live preview from the last event; a green "what this step does" sentence per step; subject-less triggers get their own fitting steps and no Update a detail. **2** recipes per event. **3** boxes only. Phone **4** (recommended) / **5** / **6**.

### 132 · Automations › Review step — **sent 2026-10-22 — Jose: "go ahead with all your recommendations" (1 and 4)** (`docs/previews/automations-review-step-layouts.html`)

Jose: "could definitely use some better ui-ux" (one sentence with "not chosen yet" gaps, "Where you are now" twice, no test, no clear Turn on). **1** (recommended) plain sentence with missing parts in red as what is needed, each part a link to its step, the real place named once; a checklist per step (✓/⚠/✕, reason, Fix) that blocks Turn on while any ✕; Try it: dry-run on a chosen recent event (or an example) showing conditions and each step's would-do with real values, nothing sent; Save switched off / Turn on. **2** receipt cards per step. **3** today's sentence + Try it. Phone **4** (recommended) / **5** / **6**. Server needs: dry-run endpoint over a chosen past event, per-step readiness reasons.

### 131 · Automations › Only if step — **sent 2026-10-22 — Jose: "go ahead with all your recommendations" (1 and 4)** (`docs/previews/automations-only-if-layouts.html`)

Jose: "What this event carries" wastes space, show an example; the "Is" list is confusing. **1** (recommended) a condition is a sentence ("Only if [Role] [is one of] [Technician, Office]", then "and …"); field menu with plain labels + last-event example, *_id fields under "Show technical details"; operator menu filtered by field type ("changed" only on change events), each with a one-line example on this field; value is a picker for closed sets; carries = compact table Detail | Last time | Use (adds a condition); "Tested on the last 20 events: would have run N". **2** ready-made phrase chips. **3** today's boxes with help text. Phone **4** (recommended: stacked sentence) / **5** / **6**. Server needs: field labels + type + options, last-event example values, dry-run over recent events.

### 130 · Automations › When step — **sent 2026-10-22 — Jose: "go ahead with all your recommendations" (1 and 4)** (`docs/previews/automations-when-step-layouts.html`)

Jose: the right panel repeats the card and changes when trigger-fields load; use the sidebar icons; add more. **1** (recommended) category rail with sidebar icons and groups, "Most used" first, empty categories hidden; compact cards (name, one line, "N in 30 days · N details · N rules"), never-raised sorted last and dimmed; right panel drawn at once with placeholders, adds what the card lacks: full description, details in plain words, last 3 occurrences, rules using it (links), often-paired Then steps. **2** chosen card expands in place. **3** one grouped searchable list. Phone **4** (recommended: icon chips, ⓘ opens details) / **5** / **6**. Server needs: per-event recent examples, rules count, often-paired actions, field labels.

### 129 · "Where it applies" scope picker — **sent 2026-10-22 — Jose: "go ahead with all your recommendations" (1 and 4)** (`docs/previews/scope-picker-layouts.html`)

Jose: "a bit ugly and confusing", wants Entire organization / Company + branches / Company branch with dividers. Today two flat lists ("Main — Ace Air Duct", "Ace Air Duct and everything under it"). **1** (recommended) one grouped list: Entire organization, divider, each company bold with "N branches" (= company and its branches), its branches indented by name only, single-branch companies one row, dividers between companies, search keeps the company header above a matching branch, switched-off branches greyed "off". **2** drill-down org > company > branch with breadcrumb. **3** two columns. Phone **4** (recommended: same grouping full screen) / **5** / **6**.

### 128 · Financials › Invoices: charge sheets, invoices, settings — **sent 2026-10-21 — no answer in 10 min, went with 1 and 4** (`docs/previews/invoices-layouts.html`)

Financials asked (session/invoices; child module `invoices` under `financials`). **1** (recommended) sub-tabs Charge sheets | Invoices | Settings; sheet = FinancialGrid-style grid (branch rows: Total jobs + a column per charge line, totals row/column), "Make invoices · N branches" drafts per non-zero branch and skips already-invoiced ones; invoices list with Sent/Unpaid/Overdue totals, status/branch/period filters, status chips; invoice side pane with Draft→Approved→Sent→Paid steps, PDF-like preview, lines editable while draft, next action as primary (Approve / Send… / Record payment), Void under More with a reason, approver and payments listed; Send… shows recipients, subject, email preview + PDF. Settings as sentences: charge lines (drag order, on/off, Airtable source or typed), email style, due days, prefix with next number. **2** full-page document. **3** sheet and invoices merged. Phone **4** (recommended: totals + invoice cards, pane full screen; sheet as branch list) / **5** / **6**.

### 127 · Jobs › Project Management: Dashboard | Pending Jobs | Settings — **sent 2026-10-21 — no answer in 10 min, went with 1 and 4** (`docs/previews/project-management-dashboard-layouts.html`)

Jobs asked (stats?from&to, filter=recently_paid). **1** (recommended) range segment Today/Yesterday/This week/This month/Custom + branch; five tiles Paid (amount only with financials), Status changes, Tag changes, Cancelled, Deadlines due; a tile lists its jobs below (what · when · by Person/Automation/Workiz); a job opens the shared timeline at the side; Pending Jobs gains a default "Recently paid" stage with the same range; Settings: watched statuses as removable chips + "cancels N days after its estimate". **2** a filtered event feed. **3** tiles plus a per-day chart. Phone **4** (recommended: 2×2 tiles, drill full screen) / **5** / **6**.

### 126 · Work Hours › Paid Time Off: balance, reset, settings — **sent 2026-10-21 — Jose: "good" (1 and 4)** (`docs/previews/paid-time-off-layouts.html`)

Desk relayed Jose; HR builds the data. **1** (recommended) list with days Left per row (orange "From <date>" chip when not yet usable, "Start date unknown"), search + Everyone/Can use/Not yet; panel with Total/Used/Left tiles + bar, "Resets <date>, their work anniversary", can-use-from, carry-over; Used has Change (edit-PTO permission) opening a side pane with number + required reason, changes listed with who/when. Settings as sentences: earns N days every Week/Month/Quarter/Year; usable N Days/Weeks/Months/Years after start; resets On each person's work anniversary / Every Jan 1 / Never; carry over; a live example line. **2** one table with inline Used. **3** a card grid. Phone **4** (recommended: own tiles on top, list below, panel full screen) / **5** / **6**.

### 125 · Scheduling › Service areas drawn on a map — **sent 2026-10-21 — Jose: "yes go ahead" (1 and 4)** (`docs/previews/service-areas-map-layouts.html`)

Scheduling asked (efbd6e645: area map, areas-map, polygons preview/save, delete with move_zips_to). **1** (recommended) all areas on one map (colour per branch / per area) with ZIP dots, area list, draw/reshape tool, a review panel (adds, belongs to another area with Take it, would drop out, typed outside kept), Save/Clear drawing; delete asks in a side pane where the ZIPs go. **2** one area per page with its own map. **3** ZIP lists with a view-only map. Phone **4** (recommended: view-only map + list) / **5** / **6**.

### 124 · Financials › Settings › Airtable sources — **sent 2026-10-21 — no answer in 10 min, went with 1 and 4** (`docs/previews/airtable-sources-layouts.html`)

Jose 2026-10-21: "i feel like this screen can be better." **1** (recommended) a role × branch matrix (plus All branches), each cell the mapped table with rows, last copy (red when stale), Mirror/Native, empty "— · Add"; a cell opens a side pane (base/table, branch, valid from/until, Copy now, Remove); role, branch and search filters. **2** grouped by Airtable base. **3** grouped by role. Phone **4** (recommended: one branch column as a list) / **5** / **6**.

### 123 · Jobs › Project Management — **sent 2026-10-21 — no answer in 10 min, went with 1 and 4** (`docs/previews/jobs-project-management-layouts.html`)

Jobs asked (session/jobs-pm 80d4792c6: rows with stage/waiting/timeline, meta.watched, deadline 30 d). **1** (recommended) a table longest-waiting first with a stage segment + counts, branch and min-days filters, deadline pill (red inside 7 days, past due), a row opens its timeline beside it, money only with jobs.financials; empty watched → a pointer to settings. **2** a board by stage. **3** a card per job with a timeline. Phone **4** (recommended) / **5** / **6**.

### 122 · Jobs › notes (off Workiz, slice c) — **sent 2026-10-20 — no answer in 10 min, went with 1 and 4** (`docs/previews/jobs-notes-layouts.html`)

Jobs asked (235d841b0: notes newest first, POST jobs/{job}/notes, append-only). **1** (recommended) a Notes · n tab beside Photos & files, box + Add note (Ctrl+Enter) on top, notes with initials/name/time, latest-note line on Details, greyed without jobs.edit. **2** at the end of Details. **3** one timeline with history. Phone **4** (recommended) / **5** / **6**.

### 121 · Financials › Home as cards (Airtable-style) — **sent 2026-10-20 — Jose: "go ahead with ur recommendations" (1 and 4)** (`docs/previews/financials-home-layouts.html`)

Financials asked (data-driven cards: role groups + native tables). **1** (recommended) equal-size cards, 4 across: icon/colour, name, one live figure, source badge (Native / Airtable mirror / both), refreshed-at; Your areas then Your bases + New base; search from 8 cards; no Airtable → Native badges only; card opens the area with table chips and view switch. **2** a list. **3** big-number tiles over small cards. Phone **4** (recommended, 2-up) / **5** / **6**.

### 120 · Integrations › Workiz web login (signed-in session handoff) — **sent 2026-10-20 — Jose: "go ahead with all ur recommendations" (1 and 4)** (`docs/previews/workiz-web-login-layouts.html`)

Workiz asked (workiz_web: link, extension, accounts→branch). **1** (recommended) Signed-in session becomes Workiz's third method: Get a link (30 min, Copy) → extension paste + Send → the page turns green by itself; account→branch table; red Signed out with Send it again; Jose's terms sentence on the card; extension popup restyled to the same steps. **2** a separate tile. **3** set up from Reports. Phone **4** (recommended: status + table, handoff needs a computer) / **5** / **6**.

### 119 · Jobs › Photos & files: the job's own (off Workiz, slice b) — **sent 2026-10-20 — Jose: "go ahead with all ur recommendations" (1 and 4)** (`docs/previews/jobs-files-layouts.html`)

Jobs asked (attachments API, attachable_type=jobs). **1** (recommended) "Added in EzBizi" grid + Add + drop zone, per-tile progress and failure, Remove asks once; "From Workiz · read-only" below with a copied-in-later note. **2** one grid with a Workiz badge. **3** EzBizi | Workiz tabs. Phone **4** (recommended: Take a photo + Choose files) / **5** / **6**.

### 118 · Jobs › type, sub-status and tags (off Workiz, step 1) — **sent 2026-10-20 — Jose: "go ahead with all ur recommendations" (1 and 4)** (`docs/previews/jobs-type-tags-layouts.html`)

Jobs asked (cff7eceae: job_type, sub_status, tags, meta.labels). **1** (recommended) Status · Sub-status · Type pickers on the panel's header line, tags as removable chips under the title with + Tag (search the words in use, add a new one); list: type as an outlined label after the number, two tags + "+N"; Type and Tags (any-of) filters. **2** a Details card + list columns. **3** coloured tag dots. Phone **4** (recommended) / **5** / **6**.

### 117 · Monitoring › Settings › Cameras + the bridge PC panel — **sent 2026-10-20 — Jose: "go ahead with all ur recommendations" (1 and 4)** (`docs/previews/camera-bridge-layouts.html`)

Monitoring asked (design bfed9d0cb §7, Jose approved the bridge). **1** (recommended) sites as rows, offline first in red with since/why, PC and camera count, Add a site + Wiring guide; a site opens to camera tiles (still, name, on/off, Live/Recordings/Alerts) and a bridge box (PC, version, last spoke, password stays on the PC, Pair a new PC / Disconnect). Bridge panel: on/off + a ticked checklist (on, paired, recorder found + password, cameras found, talking to EzBizi), code entry, red step with the fix. **2** a card per site with stills. **3** one table of cameras. Phone **4** (recommended) / **5** / **6**. Setup guide, wiring sheet, Recordings to follow.

### 116 · Customer Experience › GBP Listings, redesigned — **sent 2026-10-20 — Jose: "go ahead with all ur recommendations" (1 and 4)** (`docs/previews/gbp-listings-layouts.html`)

Jose 2026-10-20: "we can improve how this one looks too". **1** (recommended) a table led by company · branch, problems first, counts line, Showing/Not showing/All + branch + search, a row panel with address/phone/website/verification, branch, auto-reply, Open in Google. **2** cards. **3** today's list grouped by branch. Phone **4** (recommended) / **5** / **6**. Uses only fields BusinessProfileController::present sends.

### 115 · Customer Experience › Complaints — **sent 2026-10-20 — Jose: "go ahead" (1 and 4)** (`docs/previews/cx-complaints-layouts.html`)

Jose 2026-10-20 via the desk: "a table view like taskboard, that shows the progress of each bad rating". **1** (recommended) Table default with status tabs carrying counts (Open/New/In progress/Solved/Can't be solved/All), kind/owner/branch/date filters, row panel with status+owner pickers, notes, link to source; Board one click away. **2** Board first. **3** list grouped by status. Phone **4** (recommended) / **5** / **6**.

### 114 · Business Profile › Performance (A1 takeover) — **sent 2026-10-20 — Jose: "as u recommend" (1 and 4)** (`docs/previews/gbp-performance-layouts.html`)

Customer Experience asked (server ac72803c1: totals/daily/by_location + search terms). **1** (recommended) a Performance tab: dates + listing, freshness line from last_date, five tiles with change vs the period before (click puts it on the daily chart), a table per listing, its searches beside it. **2** on each listing's page. **3** in Reports. Phone **4** (recommended) / **5** / **6**.

### 113 · Automations › Build as steps with a timeline — **sent 2026-10-20 — Jose: "Looks good, go ahead" (1 and 4)** (`docs/previews/automations-steps-layouts.html`)

Jose 2026-10-20: "each of the screens has more to offer related to that space and more space to do it". **1** (recommended) a top timeline Name › When › Only if › Then › Review, one screen each: trigger catalogue by module with 30-day counts and carried details; conditions with a live 30-day would-have-run check; actions list + full-width editor + details + filled preview; Review as one clickable sentence, Try it, where, on/off, last runs. Editing opens on Review. **2** the steps as a left column. **3** one page, one part open at a time. Phone **4** (recommended) / **5** / **6**.

### 112 · Scheduling › Helpers (A1 takeover) — **sent 2026-10-20 — Jose: "Looks good, go ahead" (1 and 4)** (`docs/previews/scheduling-helpers-layouts.html`)

Scheduling asked (server 7d031767: roster + full/am/pm bookings per branch and month). **1** (recommended) A1's month calendar, coloured helper→technician tags per day, a day panel to book/change, a roster button. **2** helpers × days grid. **3** list by day. Phone **4** (recommended) / **5** / **6**.

### 111 · Settings › Organization, Roles & permissions, Activity, API activity redesigned — **sent 2026-10-19 — Jose: "go ahead" (1 and 4)** (`docs/previews/settings-tabs-redesign-layouts.html`)

Jose 2026-10-19: "u didnt finish revamping the whole settings tab, i see no changes on organization, activity - api activity / roles and permission". **1** (recommended) list on the left, the picked one on the right:
Organization tree + company page (numbers, details, settings set here, actions); Roles list + module grid (Open/Tabs/What they can do/Invite, partial boxes, Compare with, who has it); Activity table by day with Who/Module/search filters and a before→after pane; API activity the same plus a 24 h strip. **2** keep layouts, deeper. **3** full-width tables, roles as columns. Phone **4** (recommended) / **5** / **6**.

### 110 · Settings › the whole tab reviewed (Organization, Preferences, Integrations + every service page, Roles, Activity, API activity) — **sent 2026-10-19 — Jose: "Good, go ahead" (1 and 4)** (`docs/previews/settings-review-layouts.html`)

Jose 2026-10-19: "review the Entire Settings tab". **1** (recommended) one header/states/breakpoint/disabled-with-reason pattern for every tab; Settings sub tab renamed Preferences; Integrations lists every service (Twilio, WhatsApp, sending server, one Facebook);
each service page = what it's for, Connected rows (sync, error, Edit, Disconnect with confirm), Connect with plain-word fields; Activity rows as sentences that open. **2** Integrations as list + detail. **3** fix only what's broken. Phone **4** (recommended) / **5** / **6**.

### 109 · Settings › Integrations › Workiz: method picker and editable branch rows — **sent 2026-10-19 — Jose: "Sounds good" (1 and 4); waits on Workiz's server** (`docs/previews/workiz-connect-layouts.html`)

Jose 2026-10-19: "u don't get the choice, right now it only shows v1" / "you also can't edit existing ones either". **1** (recommended) Connect with a v1 / v2 / Session (soon) switch and per-method fields;
one compact row per branch (method, last sync, last error, Edit, Disconnect). **2** method cards first. **3** a table. Phone **4** (recommended) / **5** / **6**. Server: Workiz.

### 108 · Leads › compact list header and lead header — **sent 2026-10-19 — Jose: "Sounds good" (1, 2 and 4); built 6b6f476e4** (`docs/previews/leads-compact-layouts.html`)

Jose 2026-10-19: "how can we make this a bit more compressed, feel like we are using too much space inproperly." **1** (recommended) list: search + status + Filters (n) on one row, quick toggles as small chips;
Filters panel holds charge/IVR/dispatcher/dates and the In All sources. **2** (recommended) lead: three lines (name+phone+tags+source; AI line; address + Check availability + Add a note), slim customer bar.
**3** everything behind Filters and a Details fold. Phone **4** (recommended) / **5** / **6** no change.

### 107 · Leads › Stats (A1 takeover) — **sent 2026-10-19 — no answer in 10 min, went with 1 and 4** (`docs/previews/leads-stats-layouts.html` @ `fbc48f3c5`)

Leads 2f83e1218 + Field's map. **1** (recommended) one page: period/branch, four numbers with a booking gauge, map (offices sized by leads, coloured by booking rate; leads by ZIP; jobs; outcome chips)
beside top markets and dispatchers, then source / why lost / heatmap cards; **2** map first with a stats panel; **3** sub-tabs. Phone **4** (recommended) / **5** / **6**. Leads layer waits on Field's batch ZIP lookup.

### 106 · Web app › Get the app (sign-in page and Your account) — **sent 2026-10-19 — no answer in 10 min, went with 1 and 4** (`docs/previews/get-the-app-layouts.html` @ `7da98b75c`)

Jose 2026-10-19: "on web on the https://app.ezbizi.com/ we should also have the offer to download the app, desktop - android - ios." Live links from www.ezbizi.com/download.
**1** (recommended) row under Sign in, visitor's platform first, also in Your account and the avatar menu; **2** top banner; **3** one link. Phone **4** (recommended, only that phone's store) / **5** / **6**.

### 105 · Marketing › Social media library — **sent 2026-10-16 — no answer in 10 min, went with 1 and 4** (`docs/previews/media-library-layouts.html` @ `3aff94fc`)

Marketing b36699e5. **1** (recommended) From library opens a side panel that also manages (add, rename, remove with second tap); **2** its own Library tab; **3** recent strip under Attach.
Phone **4** (recommended, in place) / **5** / **6**.

### 104 · Web › hours for websites + Business Profile listings; Settings › Clock In geofence centre — **sent 2026-10-16 — no answer in 10 min, went with 1, 4 and 7** (`docs/previews/hours-gbp-and-geofence-layouts.html` @ `f914711c`)

Web 5844ed21. Hours: **1** (recommended) both groups per company in step 1, kind tag in review/results; **2** Where switch; **3** two editors; phone **4** (recommended) / **5** / **6**.
Geofence: **7** (recommended) address search + small map with radius circle + Use this place's address; **8** click a map; **9** address only.

### 103 · Leads › charge, IVR, dispatcher and date filters; edit in place; caller timeline — **sent 2026-10-16 — no answer in 10 min, went with 1 and 4** (`docs/previews/leads-takeover-filters-layouts.html` @ `3839b27d`)

A1 Leads takeover slice 1 (server 3b7022a1, Leads). **1** (recommended): the four filters in the one row (Dates menu with presets + Custom), edit name / ZIP / classification /
dispatcher in the lead's panel with Save, Back to RingCentral, Re-classify, and the caller's timeline under it. **2**: edit on the card. **3**: date chips row. Phone **4** (recommended) / **5** / **6**.

### 102 · Field › safety recordings (Record control + Recordings tab) — **sent 2026-10-16 — no answer in 10 min, went with 1 and 4** (`docs/previews/field-recordings-layouts.html` @ `1a654ec4`)

Field session for the A1 takeover. Record control on the phone: **1** (recommended) card under SOS in Field › Dashboard, **2** floating button + sheet, **3** top bar while recording.
Recordings tab: **4** (recommended) table + side-panel player with gaps and Keep, **5** by person, **6** on each job. Stop asks Done / The customer asked / Personal; notice line in notice states.

### 101 · Customer Experience › Reviews takes over A1Leads GBP — **sent 2026-10-16 — Jose: "as you recommend" — 1 and 4, then "table should be the default though not board"** (`docs/previews/reviews-takeover-layouts.html` @ `4c8d1950`)

Jose 2026-10-16: "take whatever is valuable from there towards Customer Experience tab and delete the a1leads gbp tab"; and "we are also building how the system
functions in ezbizi for when we replace his API with ours" — so Status, Notes, customer match, Report to Google and replies are EzBizi's own data and endpoints. **1** (recommended):
one filter row (rating incl. Negative, reply, time, sort, location, match), Board | Table, side panel with reply, customer + Workiz job, Status, Notes, Report. **2**: table with row
actions. **3**: tall cards with everything inline. Phone **4** (recommended, in place) / **5** / **6**. Unanswered must count replies already on Google. Server: Customer Experience.

### 100 · Marketing › Social poster: views, live progress, post detail — **sent 2026-10-15 — Jose: "go ahead" (to Marketing, on my recommendation) — 1 and 4** (`docs/previews/social-poster-layouts.html` @ `697e84df`)

Jose via the desk and Marketing, 2026-10-15: enhance ours from Rodrigo's poster; links to verify posts; delete them. **1** (recommended): the list stays with All · Scheduled · Posting · Posted · Failed tiles,
a List | Board | Calendar switch remembered per person, a pulsing tab dot and "Posting 2 of 3", an honest "Partly posted", and a side panel per post: each account's link, reason, Try again /
Dismiss, Edit / Reschedule / Cancel while scheduled, Delete from the network only where the network allows. **2**: board first. **3**: calendar first. Phone **4** (recommended, opens in place) / **5** / **6**.
Server routes are Marketing's (PATCH/DELETE post, DELETE target remote, POST dismiss).

### 99 · Settings, Suggestions, Development and Your account: UI overhaul — **sent 2026-10-15 14:30 — Jose: "Looks good", desktop 1 and phone 4, plus: "settings works as a hub for all the other settings in the app ... the settings tab should be in sync with them and have the full parity, so all settings can be found there"** (`docs/previews/settings-overhaul-layouts.html`)

Jose 2026-10-15 via the desk: overhaul Settings, Suggestions and Development and all their sub tabs, then walkthroughs and videos. He also asked directly for a walkthrough and
video for Your account. **1** (recommended): the module header (title, ▶ How to, Walkthrough) plus a sticky group index on the long pages (Settings groups, Your
account sections), Organization's List | Structure moved into its header, dialogs turned into side panels, and Development tabs kept open. **2**: a Settings home of cards. **3**: one in-page side
menu. Phone **4** (recommended, Jump to chips; Your account opens in place) / **5** / **6**. The help videos need the server to accept these page keys.

### 98 · Customer Experience › Review links (link activity) — **sent 2026-10-15 10:57 — Jose: "as u recommend" — 1 and 4** (`docs/previews/review-links-layouts.html`)

Jose 2026-10-15 via Customer Experience: a tab that measures the review links sent (green, red, opened, sent). There are six counts by branch and by person
for a date range. **1** (recommended): a Sent → Opened → Green/Red → Google/Complaint funnel, then a Branch | Person table with a total row. **2**: one table
of rates. **3**: happy and unhappy paths as bar cards. Phone **4** (recommended) / **5** / **6** match. Customer Experience builds it.

### 97 · Reports › Scoring › what the score weighs — **sent 2026-10-14 18:03; no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): desktop 1, phone 4** — **BUILT `2360c162`** (nine weights with Reports 90e9d8a5) (`docs/previews/scoring-weights-layouts.html`)

Reports (051fa5e6): the Scoring score is now conversion plus the AI's seven call checks, weighted. There are eight whole-number weights that must total 100
(defaults: conversion 40, greeted 5, explained the price 5, the other five 10 each), set with the new reports.scoring_weights tick. **1** (recommended):
a ⚖ Weights button beside Score / Most improved opens a side panel with a split bar, a slider and a number for each part, a "100 of 100" total
(amber "95 of 100: add 5" until it adds up, and Save waits), and Back to the defaults. **2**: the split as a strip on the tab, with Edit for number boxes. **3**: two steps,
conversion against calls, then the checks rescale within the calls' share. Phone **4** (recommended) / **5** / **6** match.

### 96 · How-to videos › the opening card, with the EzBizi logo — **sent 2026-10-14 15:34; no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): 3** — **BUILT** in `H:\Development\EzBizi-videos\kit` (Scheduling and Guidelines first) (`docs/previews/help-video-title-card-layouts.html`)

Jose 2026-10-14 via the desk: "do improve the Initial Screen, use the EzBizi Logo, make it look better, cause right now it looks like
something made on moviemaker". Each option has the real logo, Inter, the module icon and the video's tabs as chips, with 1.5 s of motion,
then a hold. **1**: light, like the app (logo top left, icon tile and name, cyan rule, white chips). **2**: brand cyan gradient, the logo
on a white badge with a light sweep, centred. **3** (recommended): the left column, plus the tab itself in an angled app window that
slides in. The matching 2 s end card is light with 1 and 3, cyan with 2. The voice is Matilda (approved). It goes on Scheduling first, then
every tab's video.

### 95 · Task Board › People on every board, and everyone's boards — **sent 2026-10-14 13:08 — Jose: "as u recommend" — picker 1, desktop 4, phone 7, Roles 10 — BUILT `fa70a03a`** (with Task Board `e4966d5d`) (`docs/previews/task-board-people-everywhere-layouts.html`)

Jose 2026-10-14 via the desk: he can't find People (it showed on custom boards only); he wants to add people to any board he
manages, have Administrators see everyone's boards in the picker, and set in Roles who may. Picker **1** (recommended): grouped
My boards · Shared with me · company/branch · Everyone's boards (by person, with See every board), search by board or person,
+ New board at the foot; **2**: a flat list with an owner column; **3**: a Mine · Shared · Everyone switch. Desktop **4**
(recommended): faces + People · N beside the board's name on every board, the People panel (on My board: "adding someone shares
it"); **5**: a Share button; **6**: People as a board tab. Phone **7** (recommended): a People chip under the picker; **8**: in ⋯
as today; **9**: faces only. Roles **10**: Create boards · Add people to any board · See every board, Administrator only.
Owners: Task Board (server, permissions); UI/UX builds the screens.

### 94 · ▶ How-to video: plays in the app on every platform, fullscreen and download — **sent 2026-10-14 11:20; no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): desktop 1, phone 4** — **BUILT** 8640e3b2 (session/uiux-help-video) (`docs/previews/help-video-player-layouts.html`)

Jose 2026-10-14 via the desk, verbatim: *"video should be seen on desktop, mobile, etc, right not it only works on web, secondly
the fullscreen option and also the download option all inside the app."* Windows had no player half (the panel offered "Open
the video"); video_player_win gives it one (checked on this machine). Desktop **1** (recommended): ⛶ Fullscreen and ⬇ Download
in the player's own control bar; chapters under the picture, the playing one highlighted. **2**: labelled buttons under the
picture. **3**: a ⋯ menu. Phone **4** (recommended): as 1, ⛶ turning sideways, ⬇ saving to Photos; **5**: labelled buttons;
**6**: the ⋯ menu. UI/UX builds it in tab_help.dart.

### 93 · Guidelines, a new module — **sent 2026-10-14 10:56; no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): desktop 1, phone 4, Settings 7** — built by the Guidelines session (12bba329), adjusted to the pick (`docs/previews/guidelines-layouts.html`)

Jose 2026-10-14 via the Guidelines session: a module of situation cards, each with a colour and an optional picture, that open
step-by-step instructions, and a Settings tab to create, edit and delete them. Desktop **1** (recommended): a searchable grid
of equal cards (colour band, icon or picture, title, summary, step count); a card opens its numbered steps in a side panel
over the grid. **2**: a list beside the steps. **3**: the card as its own page with ← back. Phone **4** (recommended): one
card per row, the card opening in the tab with ← back; **5**: two tiles per row; **6**: a plain list. Settings **7**
(recommended): the cards in order (drag to reorder), each edited in a side panel with the app's twelve colours and steps
added, dragged and removed. Owners: the Guidelines session (server, module); UI/UX draws and reviews.

### 92 · Re-Scheduling › open spots before and after the move — **sent 2026-10-14 10:40; no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): desktop 1, phone 4** — **BUILT** (session/uiux-resched-spots, with Scheduling 528f5824) (`docs/previews/rescheduling-spots-layouts.html`)

Jose 2026-10-14 via the desk, verbatim: *"perhaps on the reschedule screen on that blank spot on the right bottom, we can
include the scheduling before and after for the area too, as in the amount of spots before and after"*. Desktop **1**
(recommended): folded into the 0429 day plan, the current day's windows (the job's window 1 → 2 open, blue) and then the
suggested day's windows with their jobs (the window it takes 1 → 0 open, amber), each day with its total. **2**: one
table, both days side by side. **3**: each day as a strip of spot squares. Phone **4** (recommended): 1 stacked; **5**: the
table; **6**: day totals only. Owners: Scheduling (the current day's windows in the suggestion); UI/UX builds the screen.

### 91 · AI Assistance › AI, as a chat — **sent 2026-10-14 09:32; no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): desktop 1, phone 4, topics to Library (7)** — **BUILT** 5af92b2a (session/uiux-ai-chat, with Grok AI 59c4988a for the WhatsApp look). The bot's welcome line was not moved to Staff bot: the Library shows the topics, and the welcome stays in Settings (`docs/previews/ai-assistance-chat-layouts.html`)

Jose 2026-10-14 via Grok AI, verbatim: *"i think, the "AI" tab and that menu on the left aren't really meant to be together. The
AI tab should just be the ask bar at the bottom , sort of like here where i can ask you stuff and the reply goes on top …
figure it out."* Plus the WhatsApp look in the app's answers (capitals at sentence starts, emojis for bullets). Desktop **1**
(recommended): one centred conversation, newest last, ask bar pinned at the foot, starter questions before the first; sources
as chips, branches with Call/Map. **2**: sources in a right rail. **3**: Topics as a side panel. Phone **4** (recommended):
the conversation with the bar above the keyboard; **5**: long answers folded; **6**: with Topics. **7** (recommended): the
topics tree moves to Library; the welcome line to Staff bot. No server change (GET ai-assistance/ask + /answer).

### 90 · Scheduling › Routing, and Re-Scheduling's job types — **sent 2026-10-14 09:18; no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): Routing 1 and 4, Re-Scheduling 7 and 9** — **BUILT** 5c412452 (session/uiux-routing, with Scheduling 673b1598) (`docs/previews/scheduling-routing-layouts.html`)

Jose 2026-10-14 via Scheduling, verbatim: *"1 technician is able to take a max of 6 jobs per day … a suggested route where it
says like Technician 1 - Technician 2 (amount of technicians based on the availability) … a Routing Tab where it shows the
recommended routes (actual routes being done on google maps when clicked)"*, and for Re-Scheduling *"[repairs and redos]
should be included, but tag them appropriately … perhaps we can have the rescheduling clickable, showing the type of jobs
set for that day and how the rescheduled one would fit"*. Routing desktop **1** (recommended): a map with one colour per
route beside a card per technician (stops, miles, Open in Google Maps), extra routes amber "needs another technician", No
location at the foot. **2**: a column per technician. **3**: one row per route, map below. Phone **4** (recommended): map,
then route cards that open in the tab; **5**: one route at a time; **6**: list only. Re-Scheduling **7** (recommended):
Chimney Repair / Redo tags, a row opening onto the suggested day's windows with the moved job dashed where it fits; **8**:
the day as a timeline; phone **9**. Owners: Scheduling (server a95f30f6, the Routing tab); UI/UX builds the screens.

### 89 · Task Board · your own boards, who is on them, and Automate — **sent 2026-10-13 17:56; no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): desktop 1, phone 4** — **BUILT** e7d82147 (session/uiux-taskboard-people, with Task Board 9ee0e915 + 21f93872): New board, People, ⚡ Automate (`docs/previews/task-board-members-automate-layouts.html`)

Jose 2026-10-13, verbatim (inbox 0120): *"we also need to be able to assign people to certain boards and create boards,
etc. so the owner of a board can assign other people to the board, read up on how monday works with those sort of
assignments. we also want easy automations as well on task board, which should be synced with the automations tab, but
easy access and only task board related stuff"*. monday.com's model carried over: Owner / Member / Viewer per board, Open
(found and joined by its company or branch) or Private (members only); a task given to someone off the board offers
membership; Administrator sees every board. Automate recipes are real Automations rules limited to the board (shown in
Automations › Your automations). Desktop **1** (recommended): + New board at the foot of the Board ▾ picker, the board's
faces, Invite and ⚡ Automate in its header, each opening a panel over the board's right side (no dialog). **2**: a board
page with People · Automate · Columns and statuses tabs. **3**: small pop-overs from the header. Phone **4** (recommended):
the board's ⋯ opens People and Automate as pages in the tab; **5**: faces and ⚡ under the name; **6**: a Board tab.
Owners: Task Board (boards, members), Automations (board scoping); UI/UX builds the screens.

### 88 · AI Assistance › Staff bot · one WhatsApp number, a menu for each role — **Jose: "great, go ahead" — editor 1, WhatsApp 4** — **BUILT** c6a1ed63 (session/uiux-staff-bot: model, API, the WhatsAppStaffBot hook, the tab). Not yet: Daily conversion has no writer (answers "coming soon"); the content Training lists as missing is entered as Library entries (`docs/previews/staff-bot-menus-layouts.html`)

Relayed by Grok AI, Jose 2026-10-13, verbatim: *"One number, menu by role"*; content: *"all of it is in ezbizi already, from
the training guides, but i also want to see it all in ezbizi so it can be modified as well, please talk to ui - ux to make a
good experience"*. Menus are Jose's: Office (locations, dispatch guidelines, each call type, software videos, call scripts,
chimney knowledge, daily conversion) and Technicians (core values, Before & After, how-to videos, guidelines, lead sale
structure, Google reviews link). Items LINK to a Training guide › chapter › section (Training's mapping), or are a Library
entry written in place where EzBizi has nothing yet, or are made from Reports (daily conversion). Editor counters follow
WhatsApp's list limits (10 rows, 24-character titles, 72-character lines). Desktop **1** (recommended): menus with their
roles on the left, the ordered items with source and Ready / Needs content, the item's editor on the right. **2**: both
menus as side-by-side columns of cards. **3**: an editor beside a live WhatsApp phone. WhatsApp **4** (recommended): a
greeting with Open menu, WhatsApp's list, then answer cards (text, the guide's link, video or photos) with Menu and Ask a
question; **5**: a numbered text menu; **6**: three buttons at a time. Phone editor **7**. Owners: Training (guides), Meta
(the number and its messages), Grok AI (answers); UI/UX builds the editor.

### 87 · Reports · one look for every tab — **Jose: "yes, as u recommend" — desktop 1, phone 4** — **BUILT** 34706c55 (session/uiux-reports-1: d6ba03b5 shell and bugs, b6be86b5 ReportGrid on Marketing/Meta/Thumbtack, 1b666c22 Google Ads and P&L, 34706c55 empty pages, library, scores). Left for later: Performance's own tables and Sources panel, the Builder preview (`docs/previews/reports-one-look-layouts.html`)

Jose 2026-10-13, verbatim: *"Please whenever you can take a look at all the tabs in reports, specially the new ones ( also
the old ones ) and see how you can improve them visually for UI-UX"*. Every tab and sub-tab was rendered with invented data,
desktop and phone, 110 shots (`scratchpad/reports_audit/shots`). There are three date controls, five table styles, four
empty-page styles, money written three ways, and padding of 16 or 32 depending on the tab. On a phone the strip clips to
"Reports, Repor…" and the tables are wider than the screen. The frame proposed for every option: one toolbar (Performance's
date control everywhere, filters as pickers, actions right), equal figure tiles with the change against the dates before,
and one table style (sentence-case headings, numbers right-aligned in tabular figures, the naming columns pinned while the
rest scroll, a Total row, losses in red, a dash for none). A row opens a panel beside the table. One empty page: what is
missing, and the one place to fix it. Desktop **1** (recommended): that frame, shown on Marketing, on Google Ads, and on an
empty tab. **2**: figures with sparklines and a chart first, the table folded. **3**: the nine tabs as a grouped rail.
Phone **4** (recommended): a Reports ▾ picker listing all nine, the date as a full-width button, figures two to a row, rows
as cards; **5**: the strip with a fade and a pinned-column table; **6**: summary first, then "See all rows". Bugs fixed
whatever is picked: the phone date-panel blank on Marketing, Meta and Thumbtack (fixed in 0ba978af); a saved report's run
printing raw cents; Performance writing 13:33 with 12-hour time; Google Ads writing "32520.77 USD".

### 86 · Scheduling › Re-Scheduling, and the Availability map's dots — **no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): desktop 1, phone 4, map 7** — **BUILT** b5f42548 (session/uiux-resched, carries Scheduling's 42e4981b) (`docs/previews/scheduling-rescheduling-layouts.html`)

Relayed by Scheduling, Jose 2026-10-13: *"a separate tab in scheduling called Re-Scheduling where it shows such suggestions
and what to replace them for, to better fit a comprehensive route for the technicians"*; on the map: *"when hovering or
click on the dot i would like to get the information from the job, like customer name, type of job, etc, see how it can
look better, also we want zoom buttons too"*; and *"make it so i can change that in the app as well on the scheduling
settings"* (the radius). Server: Scheduling's `GET /scheduling/reschedules` (42e4981b, held at the desk for this screen).
Moving changes the customer's appointment, so every option shows the suggestion first and Move is a separate confirmed
step on the existing reschedule call, for schedule editors only. Desktop **1** (recommended): rows of from → to with the
nearest job now and after and the miles saved, a map, before/after panels and Move beside the picked row. **2**: before-
and-after cards with area chips. **3**: the current and suggested day drawn side by side. Phone **4** (recommended): rows
as cards, a tap opening the job in the tab with the map; **5**: by area, folded; **6**: one at a time. Map **7**
(recommended): a hover/tap card per dot (customer, service, number, time, technicians, status, address, miles), pinned on
click, + − ⤢ top-right; **8**: label on hover, card on click; **9**: the dot picks its row in the list. Settings: the radius
and look-ahead as a Suggestions sub-tab of Scheduling › Settings, the stock settings control, built without a vote.

### 85 · Reports › Scoring · a card per person, a ranking, and Grok's read — **no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06): desktop 1, phone 4** — **BUILT** 0ba978af (session/uiux-scoring) (`docs/previews/reports-scoring-layouts.html`)

Jose 2026-10-13, verbatim: *"I also want you to build an extra tab related to performance, but more focused on the Scoring
and Performance of each employee ... name it Scoring or something ... i want to see the employees as cards, and clicking on
them shows me the current analysis that the Grok AI has given them, their score, etc, and outside of that i want to see sort
of like a ranking system, talk to grok ai, reports, etc to figure it out, i want this to be very visual"*. Built on what
Performance already holds per call (AI's score /10, the seven checks, the "to improve" note, Workiz's own /100 where Workiz
scores the branch). A person is ranked after 10 scored calls in the dates chosen; every card repeats "a guide for coaching,
not a verdict". Desktop **1** (recommended): four team figures, the ranking as bars on the left, cards with a score ring,
trend, sparkline and seven check marks on the right; a card opens in the tab (back arrow) with the score by week, the
checks against the team, Grok's read and the "to improve" notes, the ranking staying beside it. **2**: a podium for the top
three, cards below, detail in a side panel. **3**: the cards are the ranking; one opens into a wide row in place. Phone
**4** (recommended): team figure, ranked cards, a tap opens the person in the tab; **5**: podium and a ranking list;
**6**: one card at a time, swiped. Needs: a person-list/ranking endpoint and a per-person Grok read (Reports and Grok AI
asked); scoring itself is off until Grok AI's notice and privacy steps are done. UI/UX builds the client.

### 81 · App-wide · a walkthrough and a how-to video beside every tab's title — **Jose: "as u recommend" — 1 and 4** — **BUILT** (`docs/previews/tab-walkthrough-video-layouts.html`)

Jose 2026-10-13, via the desk: *"on a1tools for each main tab we had this ... a walkthrough and how to use video buttons, so
im wondering if we can put those back ... 1 - Clicking the Walkthrough icon would basically help the user navigate the tab
for the first time, highlighting parts of the app and giving description as to what does what ... using color highlighing.
obscuring the rest of the app ... 2 - The video would basically be something manual we'll be making for each tab ... voiced
... this is an app level feature, not tied to any organizations or companies btw."*

The same in every option: a tab's walkthrough is a short list of steps beside its code (the control it points at, a title,
a sentence or two, an optional "try it"), with a version. A step whose control you can't see is skipped. Seen is stored per
person on the server. A raised version offers only the new steps as What's new here. The video button shows only on a tab
with a video. EzBizi staff keep the tab → video list, so a new video needs no release. Nothing opens as a dialog.

Desktop **1** (recommended): (?) and ▶ beside the title, as in A1 Tools. A first visit offers the walkthrough in one line and
never starts it by itself. The spotlight dims everything but the control and puts the step card beside it (Back / Next /
Skip, dots). The video plays in the side panel with chapters and captions, and Keep it small shrinks it to a corner. **2**:
one (?) with a menu, and the video floats in a corner. **3**: (?) opens a Help panel with the video and the steps, each with
Show me. Phone **4** (recommended): both icons in the app bar, the step card along the bottom, and the video full width;
**5**: menu and a docked mini player; **6**: a Help page. Owner: UI/UX builds the engine, the buttons and the staff list.
Module owners write their own tab's steps. The desk makes the videos, Scheduling first.

Built (4fcd185a). Walkthroughs are data in `client/lib/core/tab_tours.dart`, keyed by module: steps name the
`ValueKey<String>` they point at, and the Task Board has the first one. The (?) and ▶ sit beside the title in
`ModulePageHeader(beside:)`, and the offer sits under it. Seen is stored as the `help.tours_seen` preference (module →
version). Videos come from `GET help/videos` (table `platform_help_videos`), set in Staff › Help videos with link, length,
chapters ("0:42 Booking a job", one a line) and an optional .vtt. A video with burned-in captions needs no .vtt. They play in
the side panel, Keep it small moves them to a corner, and where a platform has no player (Windows) Open the video uses the
browser. Adding a tab's steps: give its controls keys and add a `Tour` under its module key; raise `version` and mark new
steps `since:` to offer them as What's new.

### 84 · Task Board v2 · subtasks, your own columns and statuses, a board picker — **Jose: "Love it go with ur recommendations" — 1 and 4** — **BUILT** 828e7bf6 (`docs/previews/task-board-v2-layouts.html`)

Jose 2026-10-13, via Task Board, on the live 0.0.0418 screen: *"I feel like this can be drastically Improve, tasks should
have sub tasks as well, i should be able to make custom columns, etc, please take the time to look at workiz and their UI,
i should also be able to make custom status, and columns, with options wether they are text based, numerical, status,
etc."* In the organization view the board tabs wrap to four rows of about 80, many identical ("Main · 0" ×20). The same in
every option: a board picker grouped company › franchise (My board first, search, open counts) replaces the flat tabs.
Columns are per board in seven types: Text, Number (unit), Status (own labels and colours), Date, Person, Dropdown (own
options) and Checkbox, each added, renamed, moved, hidden. Statuses are per board, each marked done or not. Subtasks open
under their task, like Workiz's job checklists, with "2 / 5" on the task. Desktop **1** (recommended): one Board ▾ button
with the grouped list, + at the end of the header to add a column, ⋮ per column, ▸ opening subtasks in place, Statuses…
in a side panel. **2**: a rail tree of boards and a Customize board panel. **3**: Company ▾ Franchise ▾ pickers and
subtasks as a checklist in the task panel. Phone **4** (recommended): a full-width picker opening a board list page, cards
with custom columns as lines and subtasks under the card; **5**: a Boards tree page; **6**: two pickers. Owner: Task Board
(backend); UI/UX builds the screens.

### 83 · Scheduling › Availability · earliest and suggested, with the route — **Jose: "go ahea with ur recommendations" — 1 and 4** — **BUILT** 8eee5ae3 (`docs/previews/scheduling-suggested-availability-layouts.html`)

Jose 2026-10-13, via Scheduling: a zip search shows **Earliest** and **Suggested** availability. Suggested is the open
window nearest booked work, so a technician does both, with *"a small map with an animation of the route being drawn
between the selected zip code and the other jobs it's analyzed for the suggestion"*. The radius is a setting. The same in
every option: Earliest names its nearest booked job's distance, the trade-off. Suggested says how many jobs are within the
radius, the nearest, and who is already there. `same_as_earliest` gives one line instead of two cards, and no suggestion
gives a sentence. The map draws a line from the zip to each analyzed job, nearest first; those in the radius ring light
green and the rest stay grey. It plays once, then Replay, and reduced motion shows the finished state. Field's street map
sits behind it once Google Maps is set up, otherwise a schematic. Miles are straight-line and say so, and with no `origin`
there is no map. Desktop **1** (recommended): the two cards side by side, the map under both. **2**: Suggested large with
"Sooner" as a line, the map beside. **3**: a 14-day strip with both marked, the map for the picked day. Phone and the Leads
side panel **4** (recommended): stacked, map under; **5**: Suggested first, map behind Show why; **6**: the strip. Owner:
Scheduling (server, settings, `availability_pane.dart`). UI/UX builds the screen half once the endpoint lands.

### 82 · Settings › Integrations · every option, connected or not (`docs/previews/integrations-all-options-layouts.html`)

Jose 2026-10-13, via the desk: *"going into Settings - Integrations only shows the currently connected, when it should also
show the currently not connected and allow to be connected from there, we are also missing the workiz and airtable logos.
So basically i should be able to see all the options so i can connected them all if i wanted."* The same in every option:
each section lists every provider the server's catalogue offers, logos throughout (workiz.svg and airtable.svg were in the
app but never mapped), and Connect on the card in the current view. Connect is drawn only where it works; otherwise the
card shows the server's "not ready" reason, or "Ask an administrator" without the permission. Desktop **1** (recommended):
every provider a full card, connected ones solid and first, the rest dashed with a line on what they're for and Connect.
**2**: connected cards, then small "+ Connect" logo tiles. **3**: one list with All / Connected / Not connected. Phone **4**
(recommended): a list per section with Connect on the right; **5**: tiles; **6**: the filtered list. Owner: UI/UX
(`integrations_screen.dart`).

### 80 · Task Board · boards for every company, franchise and person — **Jose: "as you recommend" — 1 and 4** (`docs/previews/task-board-layouts.html`)

From the Task Board session, relaying Jose: *a "Task Board" tab, Monday.com-style. Tied to Organization → Company →
Franchise. Every Company, every Franchise, and every user automatically gets its own board, assigned to them. Must work with
Automations and Reports.*

The same in every option:
- Task Board is its own sidebar module.
- The top-bar view decides which boards show (my own, the view's franchise and its company; or a company and its franchises).
- A board is coloured, foldable groups of tasks. Each task has an owner, a status (Not started / Working on it / Stuck / Done,
  in words as well as colour), a due date (late in red) and an About link to a job, lead or customer.
- A task opens in the side panel, with its updates and history; "+ Add task" sits at the foot of each group.
- Automations get task created / status changed / overdue, and create / change status / assign. Reports get tasks by status,
  owner and board, and overdue.

Desktop **1** (recommended): the boards as tabs (My board · the franchise · the company, each with a count), Table or Kanban.
**2**: a rail of boards, like Monday's workspace. **3**: "My work" across every board, plus a board picker. Phone **4**
(recommended): scrolling tabs, then cards with a status edge, grouped; **5**: the board list first; **6**: My work. Owner:
the Task Board session (backend, Automations, Reports); UI/UX builds the screens.

Built (`task_board_screen.dart`, on the Task Board backend). The boards in the view are underlined tabs, My board first,
each with your open count. Under them sit Table or Kanban and a search over task, owner, status and About. Table: each
group is a foldable card with its colour down its rows (an all-done group opens folded), with Task · Owner · Status · Due ·
About · Updates. The status cell wears the pill's colours, and a click changes it in place. Due reads Today / Tomorrow /
Yesterday or the organization's date, red when late and not done. "Add task" at a group's foot opens a box: Enter adds it
(yours on My board) and keeps the box open. Kanban: a column per status. The side panel has the name, status chips, owner,
due (Late under the label), About, Made by (an automation by name), updates with a box to write one, and a two-step Delete.
A board you can only read says View only and has no Add. Below 760 px each task is a card with its status down its edge.
Not built yet: the Owner and Status filter menus (search covers both), dragging between Kanban columns, opening the About
record, and @mentions. The Owner picker offers you and the people already on the board until the board sends who can be
given tasks (asked of Task Board).

### 79 · Monitoring · Files · like Windows Explorer, behind the authenticator — **Jose: "as u recommend on everything" — 1 and 4** (`docs/previews/monitoring-files-explorer-layouts.html`)

Jose 2026-10-12, via Monitoring: *"have the files system require the authenticator code as well for extra security, and also
if possible, can we make it sort of like windows explorer, where for example it shows previews of pictures, etc ? as well as
quicklinks like desktop download, etc."* Monitoring is building the server half:
- an unlock by authenticator code that lasts 15 minutes and slides with use (403 files_locked, then asked for again in place);
- quick_links, only the folders that exist;
- a preview on select (up to 5 MB, logged as a download), with no thumbnail grid.

Desktop **1** (recommended): once chosen, the computer folds into a picker at the top. Explorer's three panes follow: Quick
access and drives, a details list (Name, Modified, Size, sortable, type icons) and a preview pane. A green "Unlocked · 14 min"
pill and Lock now sit in the toolbar, and the lock card stands in the pane in place of the files. **2**: the rail stays,
quick links become chips, and the preview opens in a side panel. **3**: one tree, computers with their quick links under
them. Phone **4** (recommended): the code, the computer, quick-link tiles, the list, then a picture full width; **5**: chips;
**6**: tree. Owner: Monitoring (`files_tab.dart`); built once the server lands.

Built, first pass, on today's service (the lock and quick links follow once Monitoring's server lands). Once chosen, the
computer is a picker (MenuAnchor) over three panes. Quick access lists This PC and the drives remembered from the roots. The
details list has Name / Modified / Size, sorting by column with folders first, type icons, and dates in the viewer's format.
The preview pane beside it from 1000 px: choosing a picture fetches it once (≤5 MB, logged as a download) and shows it;
anything else shows its type, size, date, where it is, and Download. Below 1000 px, and on a phone, the chosen file stands in
for the list with "Back to <folder>". Tapping a file now chooses it, as in Explorer, and Download is the button in what it
shows.

Built, second pass, on top of Monitoring's server half (fa31bbd5, so the two ship as one release). When the server answers
`locked`, the lock card stands in for the panes: one 6-digit field, Unlock, the server's sentence under it, and "the same
one you sign in with" (Monitoring confirmed it is the person's own TOTP). Once open, the picker bar shows "Unlocked until
3:42 PM" from the server's `unlocked_until`, which every request refreshes; it is never a client-side countdown. **Lock now**
locks on the server (DELETE) and drops the fetched pictures. Quick access lists the quick links above This PC and the
drives; on a phone they come first at This PC. An older agent sends none, and nothing is drawn.

### 78 · Reports · Performance · tables instead of sentences — **no answer in 10 min — went with the recommendation (Jose's standing rule) — 1 and 4** — **BUILT** (`docs/previews/reports-performance-tables-layouts.html`)

Jose 2026-10-12, with a screenshot of Calls: *"look at all of the available tabs and options and see how you can improve the
UI, maybe add some more columns, cause the stuff we keep adding keeps overflowing."* Calls draws each call as a sentence
with no headings: direction, client and result share a 230 px badge box and wrap, and every row has a second line for "Not
transcribed yet" or "Claude: — · Grok: ETA". The same in every option: Calls becomes a table with pinned headings, like People.
Each fact gets its own column, a row is one line, and the details open on demand; the Claude/Grok switch moves into the
filter bar, and a search box is added. Desktop **1** (recommended): Time · In/Out · Caller · Line called · Client · Result ·
Answered by · Reason ✦AI · Talk · ▶, with a row opening in place (transcript, recording, both AIs, numbers, Mark booked).
**2**: the same table, with the row opening in a side panel. **3**: a list with the call beside it. People gains Missed and
Answer rate in every option; Overview is unchanged. Phone **4** (recommended): three-line cards that open in place; **5**
rows that open a page; **6** grouped by hour. Owner: Reports (`experimental_call_report.dart`).

Built: Calls is a table under pinned headings (`_callsHeader`; `_cells()` lays out the headings and every row from one list).
The columns are Time · In/Out · Caller · **Number** · Client · Result · Answered by · Reason ✦AI · Talk · play/open. **Line
called** stayed inside the opened row rather than becoming a column: on a tracking number RingCentral's caller label already
names the market and source, so the column would mostly repeat the Caller. Any row opens on tap. A missed call shows its
numbers and voicemail. An answered one shows its words (folded to the first line), both AIs' readings, the numbers, and
Convert to booked where allowed. "Not transcribed yet" shows only inside an opened row. "Reasons by Claude / Grok" moved into
the bar, the reason counts sit above the table, and a search finds a caller, number or person. Below 1000 px each call is a
three-line card. People gains **Not picked up** (rang − answered) and **Answer rate**, both sortable.

### 77 · AI · one marker, and where each AI feature lives — **no answer in 10 min — went with the recommendation (Jose's standing rule) — 1 and 4** — **MARKER BUILT** (`docs/previews/ai-marker-placements-layouts.html`)

From the Grok AI session, 2026-10-12, which relayed that Jose approved an AI program today. It asks where eight AI features
live and how a person can tell what AI decided or wrote. The features: call summary and next step, the asked-but-didn't-book
follow-up, the complaint alert, dispatcher scoring and coaching, the AI-filled name and ZIP with lead sort and flags (these
exist, unmarked), the Performance call labels (exist, unmarked), draft replies (half exist), and the weekly report. The same
rules hold in every option: the existing `auto_awesome` glyph, the word AI never left to colour alone, a tooltip saying where
it came from and how to change it, the marker gone once a person edits the value, nothing sent to a customer without a person,
and guesses worded as guesses with a one-tap "not right". Marker **1** (recommended) is a chip after a value plus a badge on
an AI-written card; **2** a tint and dotted underline; **3** the glyph only. Desktop **1** (recommended) puts each feature
where the work already is: the summary at the top of the call card with Show full transcript under it, a complaint strip on
the lead, a Follow up view in Leads, and a Call score column with a Coaching panel in Performance › People. **2** puts AI in
one place (an AI tab on the lead). **3** is 1's placement with the glyph. Phone **4** (recommended) as 1, plus the weekly
WhatsApp report; **5** as 2; **6** as 3. Build: `AiMark` in `client/lib/widgets/` (UI/UX); each feature's owner places it.

Built: `AiMark` (`client/lib/widgets/ai_mark.dart`): the chip, and `AiMark.badge(label)`, with a tooltip. It is placed on what
exists today and can be shown to be AI's: Leads' **Suggest** reply ("Draft by AI" until the words change), A1Leads GBP's
**Draft with AI** (the same), and Performance's call reasons (the chip before the counts and before each call's "Claude: … ·
Grok: …"). **Not marked yet:** the AI-filled name and ZIP. LeadsRead fills them without recording that AI did, so Leads
were asked for a per-field flag. Features 1–4 and 8 are new; the Grok AI session places the mark as each one lands.

### 76 · Marketing · Social posts · a dashboard and a full composer — **Jose: "2 definitely" — 2 and 5** — **BUILT** (`docs/previews/social-posts-dashboard-composer-layouts.html`)

Jose 2026-10-12: *"Now that we got the META Approval i believe we ought to improve this a bit and divide posts between a
Dashboard that shows all of the data and a full fledged composer."* Today Posts is the composer with the tiles and the
twenty newest under it, and Calendar behind a second chip; failures print the network's raw answer in red. **No likes,
reach or followers in any option:** the Meta review declared that EzBizi does not read followers, comments or analytics
(`docs/meta-app-review-submission.md`, pages_read_engagement and Instagram), so reading them needs a new App Review first.
Desktop **1** (recommended) Posts → Dashboard · Composer · Calendar like the Newsletter. The dashboard has the tiles plus
Pages and Next one goes out, Needs attention with Retry, All posts, Coming up and Accounts. The composer takes the full
width with a drop zone, a large preview and the publish bar fixed at the bottom. **2** one tab row fewer: Dashboard ·
Compose · Calendar · Accounts · QR Codes, a timeline with a dot per account, every network's preview at once (needs a
permission split). **3** a card per account with sent and failed counts (needs a server count), accounts down the side
of the composer. Phone **4** (recommended) as 1; **5** as 2; **6** as 3, with a three-step composer. Owner: Marketing
(`client/lib/screens/marketing/social_media_tab.dart`).

Built (client, `social_media_tab.dart`): Social Media Management's tabs are **Dashboard · Compose · Calendar · Accounts
· QR Codes**, opening on Dashboard. **Permissions split (Marketing, server):** sharing `marketing.posts.view` across
three tabs failed `module_tabs_registry_test`, rightly, because Roles would have said "Posts" for tabs nobody can
find. So each is its own grant (`marketing.{dashboard,compose,calendar}.view`). Migration `2026_10_12_400000` gives
every holder of the old key all three. GET posts takes any of them, POST needs Compose, retry needs Dashboard or
Compose, and New post shows only with Compose. Dashboard: the server's
Scheduled / Sent / Failed tiles (they still filter) plus a **Pages** fact (`FactTile`, now shared in `list_table.dart`
with the Newsletter; `FilterTiles(facts:)` lays it at the tiles' width). The timeline puts Coming up first ("Goes out"
and the promised wall time and clock), then each day newest first. A row has its status word as well as a dot per
account, the network's refusal (2 lines) and Retry; tapping it opens the post panel. The Accounts card shows Can post /
Cannot post with the server's reason, plus Connect and Manage accounts. Compose: the full pane; every ticked network's
likeness side by side (a swipe pager with tappable dots below 360 px); a bigger words box; a picture or video
dropped anywhere on the pane attaches (`desktop_drop`, first image/video only); and the publish bar fixed at the
bottom ("Going to 3 accounts · now" beside Publish). Now / Later stays in the form, so the date picker can open
downward. **Not built:** "Change" on a scheduled post (no edit endpoint), and per-account sent/failed counts (option 3's).

### 75 · A-1 booking page · more modern — **no answer in 10 min — went with the recommendation (Jose's standing rule) — 1 and 4** — **BUILT** (plugin 1.26.11) (`docs/previews/a1-booking-page-layouts.html`)

Jose 2026-10-09: *"How can we make this look more modern"* (a-1chimney.com/booking, the plugin's `[ezbizi_booking]`).
Today: three titles for one question (page, orange card bar, step), a "12345" placeholder, half the fixed-size card
empty under the first step, no sign of how many steps follow or what happens after. All options keep the fixed size
(Jose 2026-10-08) and use it: one title per step, a step tracker (ZIP → Day → Time → Your details), a big ZIP box with
a pin, tap-sized day and window cards, a foot line (no payment to book, we call to confirm, the phone number). Desktop
**1** tracker on top and "how it works" filling the first step (recommended); **2** a dark reasons panel beside the
steps; **3** one screen, ZIP with the days and windows under it. Phone **4** (recommended) as 1; **5** reasons as a
strip; **6** one scrolling screen. Owner: Web (`wordpress-plugin/ezbizi/enquiries.php`). The chat bubble is already
off this page and the reviews page (4fa6d6e0, plugin 1.26.10, Jose's ask the same day).

Built: the step titles stay Jose's own of the same morning (7d7a28b5), and the tracker has dots and words, no
numbers (his "no 01/02"). The widget's title is kept for screen readers only. Same fields, endpoints and refusal copy.

### 74 · Leads · calls in a lead's conversation — **no answer in 10 min — went with the recommendation (Jose's standing rule) — 1 and 4** — **BUILT** (`docs/previews/lead-thread-calls-layouts.html`)

Jose 2026-10-09, with a screenshot of a lead's thread: *"i wonder if we can make this look a bit better"*. Today: "Call ·
3:14", a small letter-spaced line, a 360px play bar reading "--:--" until it loads, the words in a grey box headed
Transcript; cards of different widths. All options: every call as wide as the thread, an icon and colour for who
called (customer green, us blue), the time on the right, the player showing the call's real length before it loads,
the words as a quote with a rule (3 lines, Read all), Transcribing… as a small chip. Desktop **1** a card with the
icon, title and time on one line, full-width player, the quote (recommended); **2** calls as chat bubbles with a play
pill; **3** one line per call that opens. Phone **4** (recommended) as 1, the time under the title; **5** bubbles;
**6** one line each. Owner: Leads (`client/lib/widgets/lead_thread.dart`); the length before load needs a small
`RecordingPlaybackBar` option (Phone's).

Built: plus Jose's follow-up the same day, *"can we also add 1.5, 2 , speed, so u can listen to them faster"*:
1× / 1.5× / 2× on every RecordingPlaybackBar (one stepping button when the bar is under 360 px), kept on the shared
player so it holds for every recording on a screen. `knownLength` shows the call's length before loading (zero or
less still reads --:--, Phone's condition). The transcript keeps the label "Transcript" and four lines (pinned by
Leads' tests); Transcribing… is a chip with a still icon.

### 73 · Automations · an overhaul — **no answer in 10 min — went with the recommendation (Jose's standing rule) — 1 and 4** — **BUILT** (`docs/previews/automations-overhaul-layouts.html`)

Jose 2026-10-09: *"take a look at the Automations Tab and improve it… it's one of the screens we havent been paying
attentions and could use an ui-ux overhaul"*. Today: cards with a red Off chip beside an off switch, "When Jobs ›
Created · version — · 0 runs", nothing on what the rule does or its last run; a card tap does nothing, so
everything is in a ⋮ menu (Run it now for real beside Test against a real event); New automation and a Build tab
go to the same place; the builder is a long numbered form with developer copy, and testing exists only in the list's
menu. All options: a rule reads as a sentence (When … → …), the switch is the only status (Off grey; Needs attention
only when EzBizi switched it off or the last run failed), last run and runs this week on each rule, the Build tab
goes, Test sits beside Save in the builder, Run now moves inside the rule, copy for users. Server: a `last run` per
rule. Desktop **1** cards across (Jose 2026-09-22) grouped by module with search and All/On/Off/Needs attention, a rule opens in a side panel
(sentence, recent runs, Edit/Try/Copy/Delete), the builder as When → Only if → Then blocks with the name and Save in
a top bar (recommended); **2** a sortable table, the builder in a wide panel; **3** ideas to start from and a
one-question-per-screen builder. Phone **4** (recommended) cards with the sentence, the rule opens over the list,
blocks stacked; **5** a compact list; **6** one question per screen. Owner: Automations.
Built: cards stay across (Jose 2026-09-22), grouped by the trigger's module, with search and All / On / Off / Needs
attention; the sentence uses the catalogue's trigger name and, once the list carries `action_keys` (asked of
Automations), its action labels; last run, runs this week and scope under it (server 6ffd4c1c). The Live/Off chip
is gone; Auto-disabled stays. A card opens a side panel (recent runs, Delete / Make a copy / Try it / Edit, Run it
now apart). The builder's When / Only if / Then are joined blocks with plain help; "Try the saved rule" sits beside
Save when editing. Kept on purpose: the Build tab (the tests' way in, and a create-only role's), the name as step 1
rather than in a top bar, the ⋮ menu. The phone filter wraps rather than scrolling sideways.

### 72 · Reports · Report Builder with a live preview — **no answer in 10 min — went with the recommendation (Jose's standing rule) — 1 and 4** — **BUILT** (`docs/previews/report-builder-preview-layouts.html`)

Jose 2026-10-09, relayed by the desk: *"i also want a preview view, as you select the items and be able to order them
too"*. Today: a module tree, then a name, a Columns list with no reordering, plain filter rows and Save; nothing shows
the report until it is saved. All options: a live preview of the first 10 real rows that fills in as fields are
ticked (the new column tinted), columns drag to reorder and have a remove, fields searchable and typed, room for
Filters, Sort, Group by (subtotals) and Totals, the date range and Save. Desktop **1** fields left, column chips to
drag, a row of Filter/Sort/Group/Totals buttons, the preview filling the rest (recommended); **2** the preview is the
builder (add column at the end of the header, header menus); **3** four steps with the preview beside each. Phone **4**
(recommended) as 1 stacked, preview on top, columns as a drag list; **5** the table with header menus; **6** one step
per screen. Reports owns the backend (preview rows, grouping, totals) and is listing the options against Workiz's
builder; the list fills these menus and does not move the layout.
Build notes from Reports (2026-10-09), all accepted by preview and save:
- `POST /api/v1/reports/preview` `{definition}` → `{data: {columns:[{key,label,type}], rows (first 25), count, capped}}`;
  422 `errors.definition` in words; 403 without reports.build. Column order = `definition.columns` order (78d536fc).
- `columns[].label` renames a column; `sort: [{resource, column, dir}]` several keys; a computed column refuses to
  sort (422); `GET /api/v1/reports/{id}/csv` downloads a saved report (cfb7f7b5).
- filters `{resource, column, op, value}`: eq ne lt lte gt gte contains (one value), in / not_in (list), between
  ([from, to]), empty / not_empty (none), period (today … last_year, weeks from Monday, org timezone); `match:
  'all'|'any'` (59d8a2dd).
- `group: [{resource, column, bucket?: day|week|month, label?}]` (max 2), `totals: [{fn: count}]` or
  `[{resource, column, fn: count|sum|avg|min|max, label?}]`. With either, `columns` is ignored: columns = group keys
  then totals, rows = one per group (largest first), `total` = grand total; totals alone → rows empty. Money in minor
  units (2e1ae9d7).
Built (`client/lib/screens/reports/report_builder.dart`): the preview asks again 350 ms after the last change and
drops a stale answer; columns drag by the handle beside each chip (a chip sends avatar taps to its label) or by the
row handle on a phone; tapping a column renames it; Filter / Sort / Group by / Totals open one editor in place under
the row (no dialogs); grouping starts with a count. The width gate is gone: a phone builds too (stacked under
900 px), so "Reports are built on a computer" is gone. Not built: dragging the preview's own headers, more than
one sort key, CSV and Schedule email in the builder (CSV is a saved report's), editing a saved report. Money
filter values are typed in dollars and sent in cents (asked Reports to confirm).

### 71 · Reports · Performance · the inner tabs — **no answer in 10 min — went with the recommendation (Jose's standing rule) — 1 and 4** — **BUILT** (`docs/previews/reports-performance-inner-tabs-layouts.html`)

Jose 2026-10-09, with four screenshots: *"I feel like this performance screen is a mess, specially the inner tabs, so
please review them all and make a better ui ux"*. Today a call is small text with its own empty audio bar across the
whole width and the talk time at the far edge; the line name repeats; Calls stacks up to three chip rows counted two
ways; People's opened row mixes the "Answers for" setting with the calls. All options: one call row (time, who took it,
caller with first words, line/source tags once, client and result badges, talk, one play button; player and transcript
open under that row only), one Calls filter bar (Result, Client; reasons as badges, the explanation in an info tip),
Convert to booked and the AI reason on the row. Desktop **1** same three tabs tidied, Overview tiles open Calls
filtered (recommended); **2** list with the chosen call beside it; **3** one page, figures as the filters, people as
chips. Phone **4** (recommended) as 1, calls as cards, one sideways filter strip; **5** short rows that open in place;
**6** one page. Owner: Reports (`experimental_call_report.dart`).

Built: the bar keeps Jose's word Missed (his tile name) rather than "Reached nobody", and the figures keep his
2026-10-05 order (seven tiles), restyled white. The ▶ on a row opens the recording under it and plays at once
(RecordingPlaybackBar's new `autoplay`); one open at a time, closing stops it. AI reasons stay a line under the row.

### 70 · Empty screens: one way to say "nothing here yet" — **no answer in 10 min — went with the recommendation (Jose's standing rule) — 1 and 4** — **BUILT** (`client/lib/widgets/empty_state.dart`; owners adopt it, inbox 0103) (`docs/previews/empty-states-layouts.html`)

From the full UI review (`docs/UI-AUDIT-2026-10-08.md`, across-the-app item 4): empty screens are drawn about six
ways, and many name the next step as text. One shared widget: says what goes here, one sentence, and one button
when there is a next step (shown only to who may take it). Desktop **1** centred icon, bold line, sentence, button
(recommended); **2** a dashed card at the top of the list; **3** one line with a link. Phone **4** (recommended) as 1
with a full-width button; **5** the card; **6** the line. Modules then adopt it owner by owner.

### 69 · Tabs inside tabs: telling the levels apart — **no answer in 10 min — went with the recommendation (Jose's standing rule) — 1 and 4** — **BUILT** (`TabBarLike(level:)`, set on 11 second- and third-row strips) (`docs/previews/nested-tabs-layouts.html`)

From the full UI review (across-the-app item 2): every level of tabs is the same pill, so Marketing's three stacked
rows, Web Management, Memberships and Scheduling › Settings (whose second row repeats "Availability" and "Schedule")
cannot be told apart. First row unchanged everywhere. Desktop **1** second row as underlined tabs, third as a small
switch in the toolbar (recommended); **2** second row as a list down the left; **3** the same pills, smaller and
indented. Phone **4** (recommended) as 1, the underline row scrolling with the edge fade; **5** the second level as a
dropdown; **6** smaller pills. One change in TabBarLike plus a word at each second-row call site.

### 68 · A-1 reviews page (the faces page the thank-you text links to) — **no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-08) — 4 and 1** — **BUILT** (plugin 1.26.2, the commit that marks this) (`docs/previews/a1-reviews-page-layouts.html`)

From Automations (`local_44b29c1e…`), Jose 2026-10-08: *"this is how it looks on phone, so maybe improve it a bit, send
it to ui ux"*. The page is the `ezbizi-review-faces` widget (wordpress-plugin/ezbizi/widgets.php, Web's) on every A-1
site's `/reviews/`; `?c=` adds Memberships' offer. Phone **4** (recommended) two full-width tap buttons (Great large and
green, Not so great below) in the top third; after Great a check, Thank you, one full-width orange Leave a Google
review, then a separate "For you · A-1 Membership" card with an outlined Join button the same size; **5** today's
faces side by side but big and centred, the membership as one line; **6** five stars (4–5 to Google). Desktop **1**
as 4 in a centred column; **2** as 5; **3** as 6. All: the A-1 look, 48 px targets, one button shape, 96 px clear at
the bottom with the chat bubble reduced to its icon on this page; the steps unchanged.

### 67 · Letting web and computer users know there's an app — **Jose: "Looks good", then "go ahead" (2026-10-08) — 1 and 4** — **BUILT** (the commit that marks this) (`docs/previews/app-promotion-layouts.html`)

Jose, 2026-10-08: *"Feel like we need a way to promote the app as well, so people who are using the web or desktop
know there's an app, something seamless, any ideas."* Phone apps: App Store id6805979795, Google Play
`com.ezbizi.app`; computer apps at ezbizi.com/download. Desktop **1** once, on the Dashboard, for people who have
never signed in on the phone app: a card with a QR code, the store buttons, Text me the link and No thanks; always
afterwards, **Get the apps** in the menu under their name opens the side panel (phone QR, and on a browser the
computer app) (recommended); **2** a phone icon beside the bell with a dot until opened; **3** a strip after signing in
on a browser, back after a month. Phone **4** (recommended) a slim Open-in-the-app banner in a phone's browser: Apple's
own Smart App Banner on an iPhone (one meta tag), EzBizi's look-alike on Android (an intent link: the app if
installed, Google Play if not); **5** one full screen after signing in; **6** a Dashboard card. All: the QR opens
`ezbizi.com/app`, which picks the store by device; No thanks is remembered per person; nobody with the phone app is
asked. Server asks: tell the phone app's sign-ins from a phone browser's; the website's `/app` link.

Build notes: no server asks after all. The phone app sets the preference `apps.phone_used` on every start
(`markPhoneAppUsed`, HomeShell.initState); No thanks sets `apps.phone_card_dismissed`, which also hides the Android
banner. Both declared in PreferenceRegistry, and a server that does not send them asks nobody. The QR opens
ezbizi.com/download, which already carries both store buttons. Text me the link is not built: it needs a send
endpoint and the company's number; the QR does the same job.

### 66 · API activity: what an AI or a script did with a key — **no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-07) — 1 and 4, without Put it back** — **BUILT** (client in the commit that marks this; server `8427c94d`) (`docs/previews/api-activity-layouts.html`)

From MCP / API (`local_c0e813e0…`), Jose 2026-10-07: *"we should probably have an MCP-API log to records the
actions"*, *"add all the logging to the MCP-API from now on"*, *"so changes can be reverted if needed"*. Server on
`session/ai-api`, not on main: `GET /api-keys/activity` {activity:[{at, action, who, user_id, key_id, key_name, method,
path, status, via mcp|api, sent, before}]}, newest first, 200 a page (`before=<iso>`), `key_id=`; owners see their own
keys, administrators the organization and `user_id=`. Desktop **1** an Activity link per key in the API keys card
(last few, "Show all") plus Settings › **API activity** after Roles & permissions: one table, Person / Key / Changes
only, a change opens to Field · Before · Set to, with **Put it back** (needs a server action) (recommended); **2** a
side panel per key, by day, and the same panel per person for administrators; **3** an API activity card under API
keys and the same table in Settings. Phone **4** (recommended) rows by day, a change opens in the tab; **5** rows open
in place; **6** per key only. All: Read / Created / Changed / Deleted rather than verbs; Changes only on by default.

**Put it back is out (MCP / API, 2026-10-07):** a generic replay sends a GET's shape to a PUT and can half-apply where
the two differ, which is worse than restoring by hand from the before-and-after. One-click revert would be per-endpoint
work, its own job if Jose asks. `sent` and `before` are JSON objects (a body over 200 KB stays a string). The Settings
tab is `organizations.api_activity.view`; its TabDefinition ships in UI/UX's client pin, with the screen.

### 65 · Communication · Alerts that pop up until dismissed — **Jose: "2" (2026-10-07), so 2 and its phone match 5** — **BUILT** (client in the commit that marks this; server `e1355871`, pinned together) (`docs/previews/communication-alerts-layouts.html`)

From the Deployment Lead (`local_072bee23…`), for Jose: send alerts to specific users. Verbatim: *"This sort of
alert notifications should be a pop up on the screen, that they can dismiss"*. Notifications builds the server (send,
the sent list with who dismissed, a person's open alerts, dismiss); Phone owns the Communication tab (a new **Alerts**
TabDefinition); UI/UX builds the screens. Desktop **1** a card in the top-right corner over whatever they are doing,
stacked, until Dismiss; the Alerts tab writes on the left and tracks the sent ones on the right with "Dismissed by N
of M" (recommended); **2** the middle of the screen over a dimmed app, the only blocking layer in the app, written in
the side panel; **3** an amber strip across the top of the page, one column to write and track. Phone **4** (recommended)
the card drops in at the top, the tab lists the sent ones and New alert opens the form in the tab; **5** the middle of
a dimmed screen; **6** a strip under the top bar. All: arrives with the bell's minute check (and on coming back to the
app); waits for a closed app; dismissed on one device is dismissed on all; stays in the bell's list afterwards. Left
out on purpose: whole roles or branches at once, urgency, scheduling, replies.

**Jose chose the blocking layer knowingly:** option 2's caption said it stops whatever they were doing and would be the
app's first layer that blocks the screen. It is drawn in the shell (an overlay with a barrier), not `showDialog`, so
theme_conventions_test's "no new modal dialogs" still holds for every other screen; this one is his ruling, by name.
Notifications had started a MaterialBanner (layout 3) in the bell; asked them to leave the client to UI/UX.

### 64 · Scheduling · Locations, made easier to read — **no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-07) — 1 and 4** — **BUILT** `128e95ac` (`docs/previews/scheduling-locations-look-layouts.html`)

Jose 2026-10-07, to me, with a screenshot of Scheduling › Locations (queue 49's build): *"make this page look better"*.
What reads poorly today: every row repeats its company before the branch; the phone floats far right of a sparse row;
state headings are tiny two-letter codes; 26 state chips take two rows; two search boxes look alike and do different
things; the selected branch is only blue text; the detail card is sparse. Nothing new on the server. Desktop **1** the
same two panes tidied: the lookup as the headline ("Who serves this address?"), a tight list (branch bold, company and
city small, area and phone columns, full state names as headings, a State dropdown), the selected row shaded, and a
detail card grouping each fact with its action (recommended); **2** branches as cards by state, details in a side
panel; **3** states down the left, branches in the middle, details on the right. Phone **4** (recommended) the lookup,
a tight list with a call button per row, a branch opened in place; **5** cards; **6** states first.

### 63 · Marketing · QR code stats — **no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06) — 1 and 4** — **BUILT** `814c7db8` (server `93dd2f00`) (`docs/previews/marketing-qr-stats-layouts.html`)

Jose 2026-10-06, via the desk: *"Also wonder if we can add stats to each QR Code to see how many people used it"*.
Marketing adds scans and unique scanners per day; payload proposed to Marketing (each code: `scans_30d`, `people_30d`,
`daily_30d`; `GET /marketing/qr-codes/{id}/stats?days=30|90` → every day with scans and people, plus period totals),
and what one "scanner" is decides the word on the card. Desktop **1** a 30-day line and a strip of daily bars on each
card, Stats in a side panel (30/90 days, figures, bar chart with people inside scans, paused stretch shaded)
(recommended); **2** one line on the card, the numbers only in the panel; **3** every code compared in a table above the
cards. Phone **4** (recommended) the strip on the card, Stats as its own screen; **5** one line; **6** the comparison
first. Goes with the Deactivate / Reactivate card change (Jose, same day).

Build notes from Marketing: a scanner is one phone or browser per day (IP + user agent hashed with a salt thrown away
daily), so a period's `people` is the SUM of the daily figures, never distinct across days. Say "scanners" / "daily
scanners", never "people". `daily_30d` ends today in the viewer's timezone; shade the paused stretch from
`deactivated_at` only (no pause history).

### 62 · Your account · API keys — **no answer in 10 min — went with the recommendation (Jose's standing rule, 2026-10-06) — 1 and 4** — **BUILT** `1d88b9f0` (`docs/previews/account-api-keys-layouts.html`)

From MCP / API (`local_c0e813e0…`), for Jose 2026-10-06: *"build the MCP and API so AIs can manage operations
natively"*. Backend on `session/ai-api`, not on main yet: `GET /me/api-keys` → {api_keys [{id, name, access
read|write, last_used_at, created_at}]}; `POST {name ≤ 60, access}` → 201 {id, name, access, key}, the key shown once;
`DELETE /me/api-keys/{id}` → 204. Keys leave "Where you are signed in". MCP: `POST /api/v1/mcp`, Claude Code setup
`claude mcp add --transport http ezbizi <base>/mcp --header "Authorization: Bearer <key>"`. Desktop **1** a card under
the sign-ins: list, New API key as a short form in the card, the key and setup line in an amber box with Copy until
Done, revoke confirmed in place (recommended); **2** the form in a side panel, the key waiting on the page; **3** a
"Connect an AI" card in three steps. Phone **4** (recommended) an API keys row under Signed in opening the same card;
**5** a screen per step; **6** the three steps. All: Read only is the default; the key never sits in a layer a stray
click can close.

Build notes: `ApiKeysCard` (client/lib/widgets/api_keys_card.dart) under "Where you are signed in"; on a phone an
**API keys · N** row in Signed in. GET and POST answer at the top level (`api_keys`; `{id, name, access, key}`), not
under `data`. The setup line uses the API this app talks to (`API_BASE`, else the page's origin). It was parked
outside the tree until MCP / API's 76311094 merged: ClientCallsRealRoutesTest reds every session's PHP gate while a
client path is not a route on main.

**Three kinds of key (2026-10-07), built directly in the same card.** Jose, relayed by MCP / API: *"Viewer Read /
Editor Read and edit / Full Access Read edit and delete"* and *"i should be able to decide on roles and permissions
what sort of api keys can create"*. Server `e23e125c`: each key gains `level` and `effective_level` (lower when the
role was narrowed since; null when it allows no keys now), the list gains `allowed_levels` (every kind up to the
role's strongest `api_keys.*` tick, so Viewer always comes first), and POST takes `level`. The two tiles became three,
Viewer by default and again after each key or Cancel. A kind the role does not allow is still drawn, greyed, saying so;
a role that allows none gets one sentence and no New API key; a narrowed key says what it can do now.

### 61 · AI Assistance · WhatsApp groups — **Jose: "go ahead as u recommend" (2026-10-06) — 1 and 4** — **BUILT** `afd43bbd` (`docs/previews/ai-assistance-whatsapp-groups-layouts.html`)

From Meta / WhatsApp (`local_8111908f…`): Jose 2026-10-06 replaces the Rodrigo bot's WhatsApp reports with new EzBizi
groups (`docs/REPLACING-RODRIGO-BOT.md`). Backend on main f505e9e5 under `ai_assistance.manage`: GET
`/ai-assistance/whatsapp-groups` {data [{id, purpose, subject, on_whatsapp, invite_link|null, members [{id, name,
has_phone}]}], purposes [{key, label}]} (financial, gbp_updates, messages, dispatch, `daily:<franchise_id>` per
branch); POST {purpose (unique), subject, member_ids ≤ 8}; PATCH; DELETE; POST `/{id}/publish` → 422 with a sentence
until Meta's Official Business Account (~Oct 15), members get reports privately meanwhile. Desktop **1** a new WhatsApp
groups tab: a card per group (report, name, delivery state or invite link + Copy, members as chips, a red chip for no
phone, Add off at 8), and the reports with no group yet above the cards (recommended); **2** a table with the chosen
group docked; **3** the list of reports, each with its group or "Make its group". Phone **4** (recommended) cards
stacked; **5** rows → page; **6** reports list.

Build notes from Meta: no registry tab key; gate the tab on `ai_assistance.manage` (every endpoint's permission). The
member picker reads `people: [{id, name, has_phone}]` (active users of this business) from the same GET, added at
1daadbf0, not GET /users. Built: module_tabs_registry_test reds a drawn tab the registry lacks, so Meta registered
`whatsapp_groups` at 3f460e6e; the tab needs `ai_assistance.whatsapp_groups.view` and `ai_assistance.manage`.

### 60 · Reports · Performance, rearranged — **Jose: "go ahead as u recommend" (2026-10-06) — 1 and 4** — **BUILT** `98f9a8b6` (`docs/previews/reports-performance-revamp-layouts.html`)

Jose 2026-10-06, to me: *"I believe we also need a ui revamp for the Reports Performance Tab, as we have added too many
things and the UI is not very good for it anymore."* Today one scroll holds the dates, seven figures, three panels that
open between the figures and the table (missed by reason; existing with Claude/Grok labels; not booked with Convert to
booked and its history), the Workiz account controls, the people filter and table (calls folded per person), and the
conversion note. Nothing new on the server. Desktop **1** three tabs: Overview (the figures, then Not booked and Missed
side by side, each "See all" into Calls), People (the table alone), Calls (every call, narrowed by chips, labels when
they apply) (recommended); **2** the figures down the left as the way in; **3** one page tidied into three cards. Phone
**4** (recommended) the same tabs; **5** the figures as the menu; **6** one page. All: Workiz accounts move to a Sources
side panel and a problem with them is one amber line; the figures are one size.

Built: on a phone the tabs drop their counts and Sources sits at the foot of the Overview, so the dates and the three
tabs each keep a whole row (the date bar overflowed with Sources beside it). New client is a way into Calls too, since
the Calls tab has a New chip. Calls over more than one day carry the day.

### 59 · Customers · Workiz's money and notes on a customer — **Jose: "as u recommend" (2026-10-06, relayed verbatim by Customers) — 1 and 4** — **BUILT** `afd43bbd` (`docs/previews/customers-workiz-money-notes-layouts.html`)

From Customers (`local_90d427a5…`), approved as a feature by Jose 2026-10-06. Money: `GET /customers/{id}?today=Y-m-d`
→ `data.money`, null without Jobs Financials (draw nothing), else one entry per currency {invoiced, due, past_due
(null without `today`), paid, invoices, open_estimates, open_estimates_minor}, mirroring Workiz's client header (Past
due / Due / Total revenue); scoped to the reader's branches; empty list = nothing in Workiz. Notes: `GET
/customers/{id}/workiz-notes` (Workiz session building it), live, per Workiz client {client_id, franchise, notes
[{text, created_at, author}], error?}. Desktop **1** the job panel's shape: Customer / Money (past due named in red on
the tab) / Notes from Workiz (fetched when opened, grouped by branch, a failing branch on its own line with Try again)
(recommended); **2** one panel: money first, Workiz notes folded under the customer's own notes; **3** a red line only
when past due, notes behind a button. Phone **4** (recommended) the tabs; **5** one page; **6** the red line.

Build notes from Customers: always send `?today=` (the reader's local date); `past_due_minor` null means unknown, never
zero, and leaves the tab plain; the tab is coloured only for past due > 0. "Due" is computed (the lower of Workiz's
`amountDue` snapshot and invoice minus payments, so it matches Workiz's own screen, not its API), so no "per Workiz"
wording on it. `money` is a list per currency: draw the first; empty list or null hides the Money tab.

### 58 · Jobs · what Workiz knows about a job — **Jose: "as recommended" (2026-10-06, relayed verbatim by Jobs) — 1 and 4** — **BUILT** `20c0b4bf` (`docs/previews/jobs-workiz-extras-layouts.html`)

From Jobs (`local_aa58c8ac…`), Jose 2026-10-06: *"take advantage of everything v2 has to offer"*. Server 2f38f4b1 (on
main): `data.workiz` per job: `fields` [{name, value}] in Workiz's names (71% of recent jobs), `files` [{name, url}]
(photos, files, inspection-report PDFs; 18% of jobs, ~8 each) for anyone; with Jobs Financials only `currency`,
`invoices`, `payments`, `estimates` (Workiz's own: Won, Declined, Pending, Archived, Unsent, Approved) and `timesheets`,
plus the "Pricing" field. Beside the queue-31 money card. Desktop **1** tabs in the job panel (Job with Workiz's
fields as facts under the status · Money: the card, then invoice and payments as a dated line, estimates, time on
site · Photos & files: a grid, PDFs listed) (recommended); **2** one panel, folding sections with counts; **3** the
highlights up top (chips, four photos, money with payments) and "See all from Workiz" as a page. Phone **4**
(recommended) the same tabs; **5** folding sections; **6** highlights, then a page of the rest. All: Workiz records
marked read only; no Financials, no Money anything.

Build notes, measured by Jobs on a real attachment: st.sendajob.com serves photos and PDFs with no auth and no referrer
block, but with no CORS headers, so on web use `Image.network(url, webHtmlElementStrategy: WebHtmlElementStrategy.prefer)`
(an `<img>`, which needs none). Photos are full size (one was 1.19 MB) with no thumbnail variant: set
`cacheWidth`/`cacheHeight` and build the grid lazily. PDFs open in a new tab.

Built as drawn (`client/lib/screens/jobs/job_workiz.dart`, tabs in `job_detail_panel.dart`). The queue-31 money card
moved into the Money tab, whose label carries the total. The photo grid builds every tile, so a job's ~8 photos all
load when the tab opens (`ponytail:` note; a thumbnail proxy is the upgrade). Tabs only when there is more than the job
to show; a job Workiz does not have keeps one page.

### 57 · Reports · Performance · choosing the dates — **Jose: "as u recommend" (2026-10-06) — 1 and 4** — **BUILT** `8da551c1` (`docs/previews/reports-performance-dates-layouts.html`)

From Reports (`local_a0b9e184…`), Jose 2026-10-06: *"Custom date picker on former experimental / From date to date /
Or specific date / Week is confussing what week is it ? Etc i should see the range / Maybe dropdown like google"*.
Today: Day/Week/Month segments, arrows, and `2026-09-28 – 2026-10-04`. Server (Reports' branch, 2660648c): a period
around a day (Mon–Sun weeks) or `from`–`to`, one date = from=to, up to 92 days. Desktop **1** one button that says
the dates in words, opening a Google-style picker IN the page under it (quick choices each with their dates, two
months to click one day or two, From/To boxes, a day count, Apply) (recommended); **2** chips with the range spelled
out under them and From/To fields for Custom; **3** the same picker in a side panel. Phone **4** (recommended) the
button, then a page of dates with a month and "Show 2 days"; **5** scrolling chips with the dates in words; **6** a
list to pick from, From/To under it for Custom. All: dates in words never ISO; arrows step by the chosen length.

Built as drawn, as a shared widget (`client/lib/widgets/date_range_picker.dart`: DateSpan, the presets, DateRangeBar,
DateRangePanel) so Ads' range chips (still on `showDateRangePicker`, a dialog) can move to it later. The calendar
stays still under the pointer between the two clicks of a range (the first build moved it, and the second click
landed a month away). Month-shaped ranges step by whole months; the day/month order follows the organization's date
format. Reports reviewed: no changes. Server: dd37e992.

### 56 · Inspection · the Builder, made readable — **Jose: "go ahead" (2026-10-06) — 1 and 4** — **BUILT** `2916cdcb` (`docs/previews/inspection-builder-layouts.html`)

Jose 2026-10-06: *"i think the UI for the builder is pretty much extremely hard to understand and use, it makes
no sense whatsoever."* Measured causes: a picked question's settings open under the whole list (42 rows down on
The system); screens and questions are two unrelated lists; types and conditions in code words (`choice`,
`system_type`); what an answer reveals (a failed finding's photo, what is wrong, recommended work) is invisible
and uneditable; a screen's own condition (Exterior only for Level 2) cannot be set; the Flow reads
`screen['title']` where screens are named by `name`, so it says "Screen 1–4"; Save and Publish sit at the
bottom. Data: all there today (`visible_if` on screens and fields, option `variant` and `fields`, `print`,
`code_id`, `bind`, versions); one new flag would say whether the draft differs from the published version.
Desktop **1** the form as it will look, each question opened where it stands, live phone beside (recommended);
**2** an outline of the whole form, the chosen part edited beside it; **3** a table per screen. Phone **4** a
screen's questions, one opened in place (recommended); **5** the outline; **6** the form with a pencil per
question. All: changes save as you go, Publish in the top bar, settings in a side panel, drag to reorder.

Built as drawn, with what the drawing did not settle: **the Look is not offered** (nothing reads `layout_preset`: the
run and the report draw all three the same, so the choice changed nothing; the stored value is untouched); the
"N changes since" flag is **not built** (the server does not say whether a draft differs from its version); a
phone's back row says "Screens" because the editable title under it is the name. Answers whose value is their own
words carry renames into every condition waiting for them; a keyed answer (`ok`, `not-ok`) keeps its key. Code:
`client/lib/screens/inspection/builder_{tab,model,question,phone}.dart`; tests `inspection_builder_test.dart` (the
old file, kept) and `inspection_builder_form_test.dart`.

### 55 · Marketing · QR Codes (dynamic codes for the vehicles) — **Jose: "Go ahead with ur recommendations" (relayed by Marketing) — 1 and 4** — **BUILT** `277cf4fd` (`docs/previews/marketing-qr-codes-layouts.html`)

From Marketing (`local_067b17d7…`), Jose 2026-10-05: a QR code printed for good on the company vehicles, *"1
consistent qr code"* whose web address he changes in EzBizi without reprinting. Backend by Marketing: a tab
`marketing.qr_codes.view` beside Posts and Accounts, edits need `marketing.edit`; codes {name, url (what is
printed, never changes), destination_url, scans, last_scanned_at}; create, change, delete; image as SVG (vehicle
wraps) or 2000px PNG. Desktop **1** a card per code (the real code drawn from `url`, where it goes with Change on
the card, scans, downloads; delete asked inside the card: "Printed copies will stop working"; New QR code in a
side panel) (recommended); **2** a table with the chosen code docked; **3** a list, a code opened in place. Phone
**4** (recommended) – **6**. The change copy: "The printed code stays the same; people who scan it go here
instead."

Built as drawn, with two changes the drawing did not settle: on a phone the card's ⋮ sits beside the name,
because the downloads leave it no room beside them; and cards side by side are one height, an open Change box
included. `client/lib/screens/marketing/qr_codes_tab.dart`, `test/marketing_qr_codes_test.dart`.

### 54 · Memberships · the page a customer joins from — **Jose: "go with ur recommended options as usual" (the Membership program) — 1 and 4** — **BUILT** `5d4d61c6` (`docs/previews/memberships-join-page-layouts.html`)

The signed link in the review offer and the renewal reminder opens `resources/views/memberships/join.blade.php`
(Membership session, `session/memberships`), plain HTML until now; the Membership session handed its look to me.
Desktop **1** the frame every business email through EzBizi already wears (queue 39: the business's name in a
white band with the teal rule, "via EzBizi" small), a card per plan with the price large (a monthly plan shows the
month and the year) and one Join, the thank-you on the same page (recommended); **2** one plan up front, the rest as
rows; **3** a comparison table. Phone **4** (recommended) – **6**. Needs from Membership: the CUSTOMER's company name
on the page (it shows the organization's, "A1-Group", where the customer knows "A-1 Chimney"), and an optional
one-line plan `description`.

### 53 · Dashboard · templates a person can apply — **Jose: "did we ever add the recommended layouts ? so users can click on them and it changes the layout of their dashboard that being order and size of widgets and modes" (2026-10-07, relayed verbatim by Dashboard) — 1 and 4** — **BUILT** `58c09545` (`docs/previews/dashboard-templates-layouts.html`)

Jose, 2026-10-05, to the Dashboard module: *"also make some recommended layouts, like templates a user can
apply"*. Server on main (`90ea5d42`): `GET /dashboard/catalogue` → `templates` [{key, label, description,
glances: [{key, size, hidden}]}], five built for the reader's reach (Whole business, Office and dispatch, Field
operations, Sales and marketing, People); applying is a PUT of `glances` as sent (charts kept), the standard
layout is a PUT of `[]`. Desktop **1** a Templates button in Customize opening a side panel (name, what it is for,
a miniature drawn from its sizes, Use this layout), applied at once with an Undo bar instead of a confirm
(recommended); **2** template chips under the Customize bar, same Undo; **3** five cards first in Customize, asked
inside the card. Phone **4** (recommended) – **6** the same three.

Build notes: templates now set modes too (Dashboard's 88cd5af2): entries {key, size, presentation?, hidden}, a Small
box naming no mode and keeping its stored one, so the glances are sent exactly as the server built them. Undo PUTs the
layout as it was (every box's order, size, mode, options, hidden). On a phone the Customize bar's tools take a line of
their own: beside the words they squeezed them five lines tall and pushed the boxes off a 375×700 screen.

### 52 · Customers · Memberships: Dashboard, Members, Settings — **Jose: "go with ur recommended options as usual" — 1 and 4** — **BUILT, on main with its routes** (merge `4b293c53`; price fix `64a1ccbc`) (`docs/previews/customers-memberships-layouts.html`)

From the Membership Program session (`local_9b8c5aa9…`), backend on `session/memberships` @ `19240797`, spec
`docs/superpowers/specs/2026-10-05-memberships-design.md`. Jose, 2026-10-05, on Members: *"customer list, enrollment
type, plan type, amount due, state, zip, estimate gross for client, amount paid so far"*; and to me, before the
gallery existed: *"go with ur recommended options as usual"* — so the recommended pair is built and the gallery is
the record. Desktop **1** four tiles that filter (active, renewing in 30 days, lapsed, amount due) over Jose's
columns in his order, a member opened in place with ← (this year, paid changed in its row, every earlier year,
Cancel), Add a member in a side panel; Dashboard the same figures with renewals in the next 30 days and members by
plan; Settings the plans changed in their rows and where the renewal reminder is set (recommended). **2** cards
under the month they renew. **3** a list with the member docked beside it. Phone **4** (recommended) – **6**.
Money (due, gross, paid) only with `jobs.financials`; without it those columns and tiles are not drawn.

**As built (`5eb65079`):** standalone tab files; the Membership session's shell on `session/memberships`
switches its placeholders to them (the edit is in its working copy). On a narrow pane a member is a labelled
row, not the table stacked into bare values (the "Yes Yes" lesson). Amounts are left-aligned: right-aligned
ones ran into the next column. Waiting on the Membership session: the shell landing on main, and
`GET /memberships?customer_id=` returning every year, for the member's "Every year".

**Reverted from main by the desk (`f403b70a`), rightly:** `tests/Feature/ClientCallsRealRoutesTest` reads every
api path in `client/lib`, and the seven `/memberships` routes were only on `session/memberships` (and uncommitted
in the shared tree, which is why my runs passed). "Nothing reaches it yet" is not that guard's question. The four
files sit untracked in the shared tree and land in the same commit or merge as the routes.

### 51 · Communication · Settings: General and Call flow, drawn together — **Jose: "go with ur recommendaitonms as usual" — 1 and 4** — **BUILT** `3de48638` + `3b13b750` (`docs/previews/communication-call-flow-layouts.html`)

Jose, 2026-10-05, with screenshots: *"I feel we can also improve this screen on communication - settings - call
flow"*, then of General: *"Same for this one"*. One gallery because they are one subject: General sets the company's
answer, Call flow shows each line's result. Today 7 of 8 Call flow cards repeat the same four steps (a Twilio
sentence and the raw E.164 on each), "Transcribed" / "Recorded" are drawn as buttons that do nothing, and General's
ring group "Nobody" (the cause of all seven red lines) sits in grey with nothing tying it to them. Call flow chart
hangs under a second "CONNECT" heading. Desktop **1** one screen: the company's answer once as boxes left to right,
each changed in place and saved on the spot, the wrong box red with its fix ("Pick who rings" turns seven lines
on), lines as rows saying only "follows the company" or "its own", a line opening its chart in place; General and
the chart's entry fold in (recommended). **2** both screens kept but short: General as numbered steps with a Save
bar, Call flow as one lines-by-steps table with "same". **3** what is wrong first, then the answer as an editable
sentence, lines in two groups. Phone **4** (recommended) – **6** the same three. Needs from Communication for every
option: each step's `from: company | line` on `GET /phone/call-flow`. Entry 18's rule holds in all of them: the
screen shows what the phone system will do (1 and 3 re-read the trace after each save).

**As built:** Phone sent everything first — `from` per step (33fc8f99), `default_reaches` per connection
(94be9b30), partial PATCH. The count at the top stayed (the test guarding the five silent lines asked for it);
the lines sit straight under the boxes and recording after them. A set-but-empty group is red too
(`3b13b750`). The chart opens in place with ← in its own header beside its line picker, and its node editor
on a narrow pane now draws in place instead of a pushed screen (containment). General and the Call flow
chart left the rail; `general_pane.dart` is gone and its three tests run against Call flow.

### 50 · Dashboard · widgets you move and resize, grouped like Workiz's — **Jose: "go ahead with ur recommendations" — 1 and 4** — **BUILT** `11f20d00` (`docs/previews/dashboard-sizes-layouts.html`)

Jose, 2026-10-05, with Workiz's dashboard beside ours: *"it would be nice to be able to not only move around stuff but
change the widget sizes between different available sizes, obviously smaller ones would show less information"*.
Supersedes 45 (never picked), which had sizes as its option 3. Every option: three sizes — Small one figure with its
change against the period before, Medium adds the chart, Large the breakdown and what needs someone. Desktop **1**
a row of Small tiles, then sections named like the menu's groups on a four-column grid, Customize puts S·M·L and a
handle on each (recommended); **2** a free six-column grid, resized by dragging a corner that snaps to a size; **3**
a tile row plus two lanes, sizes from each widget's ⋮ menu. Phone **4** two columns, Small half and the rest full
(recommended); **5** one column, the size is how tall; **6** swiping tiles over stacked cards. Money stays out per
Jose's 2026-09-29 rule (Workiz's sales tiles become counts); asked whether that changed. Needs from the Dashboard
module: `figures` with the previous period's value, `series`, the breakdown, `attention` rows, and `size` beside
`presentation` in `PUT /dashboard/layout`; option 2 also each widget's grid place.

**Money, Jose 2026-10-05:** *"as for the money remember its a role y permission things where ( can see financials )
should be toggled"* — replaces the 2026-09-29 rule. A box carries money only for a person whose role holds that
module's `financials`; for anyone else the server sends no amount at all. Proposed to the Dashboard module: money
only as a figure with `currency` (value and previous in minor units), never inside a sentence, so the guard can
check for the key. Pick and both messages sent to the Dashboard module 2026-10-05.

**As built (`11f20d00`):** sections are the person's own sidebar groups (`navigation.order` dividers), unnamed
like the sidebar's, with its rule between them; the menu has no group names, so the gallery's "People / Work"
headings were not real. No separate top row of tiles: it repeated each box's headline, and Small boxes are the
tiles. Move and size live in Customize (handle, S·M·L, earlier/later). With figures a box draws `notes`, not
its `sentences` (those stay for installed builds); Small and Medium count attention rows, Large lists them.
Waiting on the Dashboard module: `hidden` (then hide and "Add a box"), `notes` per glance, and PUT keeping a
stored size an entry omits. A phone picks the period from one chip, as phone 4 drew it.

### 49 · Scheduling · a Locations tab: every branch, the closest one, who to call — **Jose: "go with ur recommendations" — 1 and 4** (`docs/previews/scheduling-locations-layouts.html`)

Jovanni Martin (A1-Group), suggestion 0080, via Scheduling: *"Add a general information tab that displays company
addresses with a feature that shows the closest location to the inputed address similar to the a-1 site as well as
contact info for newbie dispatchers"*; Jose: *"Implement"*. In Scheduling, beside Availability, because the answer is
a branch whose availability is one tab away. Desktop **1** the lookup first (closest large, two runners-up), then
every branch by state beside the picked one with its note for new dispatchers (recommended); **2** a map of pins with
the lookup over it; **3** one table that gains a Miles column and sorts by it. Phone **4** lookup first (recommended),
**5** the directory one branch at a time, **6** the new-dispatcher note first. Data: the branches are already
`web_locations` (address, phone, email, map link, coordinates); Photon geocodes the typed address for free, and the
address lookup already gets coordinates and drops them; service areas say who serves a ZIP. New: `GET
/scheduling/locations`, `GET /scheduling/locations/nearest?q=` (straight-line miles plus the area serving the ZIP),
and a per-company "For new dispatchers" note edited in Scheduling › Settings. No drive time in any option: it needs
a paid routing service. Option 2 also needs map tiles, and OpenStreetMap's public tiles only allow light use.

### 48 · HR · the onboarding form, second pass — **Jose's own shape (relayed by HR), built directly** — **BUILT** (`2b0814cc`, `917551d9`, `94e92cf5`, and the saved-signature commit)

Jose, 2026-10-01, via HR: *"buttons next to the field with like a tick or x… voiding Scheduled increase interval
voids itself and scheduled increase amount… state should be first… [home address] like in the rest of the app
should have separate fields… Dispatcher Master 5000 should be highlighted on the contract… PDF (Final Result
view)"*, then hand-drawn signatures "as in A1 Tools". Built in `onboarding_form.dart`: ✓ / ✗ at each voidable
row's right edge (✗ voids every question in its `void_group`, ✓ brings them back empty); `state` drawn above
`county` whatever the version's order; the preview sends `highlight` (keys changed since the page opened) and
the document's own `<mark>` yellow shows it; the home `address` is AddressFields from the question's `parts`, saved as
`answers.address = {address_line1, …, country}` — nested, because a flat `state` part is the contract's governing
`state` (told HR, 05692793); Live | PDF on the contract pane, the PDF from `POST …/preview?format=pdf` through
`PdfView` (the browser's own viewer on web, `printing` pages elsewhere — Android's WebView cannot show a PDF;
adding it moved xml 7.0.1→6.6.1 and archive 4.3.0→4.0.9, the newest pdf this SDK resolves); the signing step
adds A1's pad (`SignaturePad`: white paper, border lit once drawn, Clear, a downstroke draws instead of
scrolling) and sends `drawing` as a PNG data URL; Sign waits for a drawing; "Use my saved signature" puts the
signer's own (`GET me/signature`) in the pad's place and "Save as my signature" `PUT`s a new one. With HR's A1
restyle (9.5pt body, 18pt title, in a `<style>` block) the live text is paper in both themes, like the PDF, and
its point sizes are scaled alike so the body reads at 15 px: flutter_html reads a point as a pixel, which drew
12pt headings below a 15 px body.

### 47 · Work Hours · a Dashboard tab, first — **out for decision** (`docs/previews/work-hours-dashboard-layouts.html`)

Jose, 2026-10-01, via Clock In: *"i'd also like to have a sub tab dashboard which lets me know details, averages,
for each employee, how many clock in on time, or outside schedule, etc, etc."* Data: `GET hr/time-clock/stats`
(559529c7). Desktop **1** seven team figures over one row per person (on time, late with average minutes, left
early, missed, outside schedule, forgot to clock out, usual in and out, hours), a person's days docked beside the
list (recommended); **2** six ranked lists; **3** a card per person with a cell per day. Phone **4**
(recommended)-**6**. Option 1/4 needs one new read, a person's days (`?user=`), and `average_in`/`average_out`
over scheduled days only. Found while drawing (told Clock In): `team.clocked_in` is "clocked in during the
period", not "now"; `outside_schedule` misses a clock-in after the day's end; General still has five dialogs, a
bottom sheet and a full-screen push.

### 46 · Monitoring · browsing an employee's PC files — **out for decision** (`docs/previews/monitoring-files-layouts.html`)

Jose, 2026-10-01, via Monitoring: *"me who has access to monitoring being able to access files on one of my
employee's computers... ask ui-ux for a good layout for it too."* Poll-driven like Remote Control (each folder
is a 1–3 s round trip), downloads in two steps, every open audited. Desktop **1** its own Files tab: the person
rail, a breadcrumb and one list, "Opened by you · recorded" on the path, a downloads tray (recommended); **2** a
folder tree beside the list; **3** launched from a PC Specs card into a side panel. Phone **4** one job per
screen (recommended), **5** from PC Specs, **6** shortcuts first. Needs a `files` capability on the agent, `list`
and `roots` on Remote Control's cache-queue shape, a two-step download, `monitoring.files.view` and
`.download` grants, and the same Lead Developer exemption Screen Monitoring has (told Monitoring).

### 45 · Dashboard · the main dashboard and its boxes — **superseded by 50, never picked** (`docs/previews/dashboard-layouts.html`)

Jose, 2026-10-01, with a screenshot: *"i also feel like the dashboard and it's widgets are a bit stale and may need
some improvements, i also want the default to be Day ( as in today ) instead of Week"* (Day built, `b3e69240`).
Today: two columns of equal cards, big donuts (Inspection and Fleet are one solid ring), numbers buried in grey
sentences, empty modules as large as busy ones. Desktop **1** six "today" tiles, cards with figures and 14-day
bars for what moves during the day, a "Needs you" list, compact boxes for the rest (recommended); **2** a to-do
list first with the button that fixes each, modules as table rows; **3** each box Large / Small / Compact. Phone
**4** (recommended)-**6**. The Dashboard module's rules hold in every option: no money in any box or tile (Jose,
2026-09-29), an empty box is muted sentences, a failed box says so, 0–4 sentences all render, snapshot boxes
ignore the period. Needs from the server: each glance's numbers as `figures` and its warnings as `attention`
rows, plus 14-day trends; send the pick to the Dashboard module (`local_0417a3f0…`) before building. Found while
drawing: Customers' "new this week" counts `created_at`, so the A1 import reads as new (told Customers).

### 44 · HR · the onboarding form: formats, pickers, and the contract beside it — **Jose's own shape (relayed by HR, urgent), built directly** — **BUILT**

Jose, 2026-10-01, via HR: *"we can't just let them put anything anywhere. contracts have format… number based
fields [no letters], country and state should have search bars, with state being first so the county adapts to
the chosen state, for works hours we could have a hours and date picker… full address fields with
recommendation… the employer should be able to see the contract on the right side, changing as they input the
information, same for the employee"*. Built in `onboarding_form.dart`: numbers take digits and one point (a
stored "20sdsadsa" says it is not a number); `state` is a US-state picker storing the full name ("FL" reads as
Florida); `county` searches `GET /places/counties` in that state and empties when the state changes;
`employer_address` is AddressFields composed to one line; `work_days` + `work_hours` are one control on the
schedule panel, written as HR's canonical text; `salary_period`/`raise_period` pick Hour/Week/Month/Year. At
≥980 wide the contract (`POST …/preview`, 600 ms after a change, HTML via flutter_html) fills the right
column; narrower, Form | Contract take turns. Sign waits for unsaved answers ("Save your answers first."),
"Still to answer" lists only the signer's side, and the stored bytes moved behind "Show the exact text".

### 43 · Inventory · Stock, Items, Orders and Locations — **Jose: "looking good, do all ur recommendations" — 1 and 4** — **BUILT** (client join of stock, items and orders; the grouped server read and History wait on Inventory) (`docs/previews/inventory-layouts.html`)

Jose, 2026-10-01, with a screenshot of Stock: *"same for the whole inventory tab and sub tabs, this is pretty
pathethic atm"*. Today Stock is one two-line card per item per place, with no totals, columns or money, and its
red Low compares one shelf against the item's reorder point, so every van reads Low. Desktop **1** four filter
tiles, one row per item with its places inside, Transfer docked on the right (recommended); **2** items down,
places across; **3** places first. Phone **4** option 1 in a column (recommended); **5** the van you stand in;
**6** what needs doing first. Every option judges an item by its total across places (`low_items`) and keeps
General first in the strip (queue #8). Option 1 needs a grouped stock read (row per item, value, on-order,
items held nowhere), on-hand on items, totals per place, and a movements list; below ~200 items the client
can join the first three itself.

### 42 · Human Resources · the Dashboard — **Jose: "looking good, do all ur recommendations" — 1 and 4** — **BUILT** (reads HR's `GET /hr/dashboard/overview`, f3f71879) (`docs/previews/hr-dashboard-layouts.html`)

Jose, 2026-10-01: *"the dashboard at human resources is also lacking, now we have a lot more information that
could be displayed here"*. Desktop **1** six figures, joined/left by month, where people work, "right now"
(on the clock, away, due for retaking), three named lists, each opening in a docked panel (recommended);
**2** what needs doing first; **3** everyone with today's status. Phone **4** (recommended)-**6**. None is client
only: option 1 needs the splits and names the server already computes and drops, the unused headcount-by-place
widget, Joined by START date, and stalled invitations as a list. Found while drawing: "68 people joined this
week" counts `users.created_at`, so the 64 A1 imports of Sep 29 count as joiners; HR fixed it to read start dates (`c2e55267`, session/human-resources).

### 41 · Training · the Learn tab — **Jose: "looking good, do all ur recommendations" — 1 and 4** — **BUILT** (`docs/previews/training-learn-layouts.html`)

Jose, 2026-10-01: *"same on training, right now library is just showing everything the same, guides, and tests
with no difference"*. Desktop **1** Continue first, then each guide joined to its test on one card, status
chips and search (recommended, client only); **2** one list grouped by what to do next, the open one docked;
**3** tracks by role (needs a track name and order on the server). Phone **4** (recommended)-**6**. Keeps Learn
1's rule: Assigned to you comes first and no filter hides it. The A1 import sets `requires_guide => false`, so
Jose's real technician test is open now; only an invented test shows "after the guide".

### 40 · AI Assistance · the AI tab — **Jose: "looking good, do all ur recommendations" — 1 and 4** — **BUILT** (`docs/previews/ai-assistance-layouts.html`)

Jose, 2026-10-01: *"we need also a layout redesign for the AI Assistance tab"*. Today: four full-width folding
rows, answers two clicks deep, branches as plain lines, the map link dropped, bold stripped. Desktop **1**
topics down the left, the answer in the middle with its source, branches as cards with Call, Map and Copy on
the right (recommended); **2** a conversation like the WhatsApp line; **3** a start page with the whole Library.
Phone **4** (recommended)-**6**. Client only; the Ask is keyword search, not an AI, and no option pretends
otherwise (2026-09-25 "build free for now"). Optional: each answer's audience on `/menu` and `/ask` for a
Staff only pill.

### 39 · Every email EzBizi sends · one branded frame — **Jose: "i like it" — 1** — **BUILT** `e15f8fb4` (Automations passes the kind and the rule's name on top) (`docs/previews/ezbizi-email-layouts.html`)

Jose, 2026-10-01, with the overbooking warning in Gmail: *"can we make the overbooking report email more
formatted, you know, ezbizi colors, etc, not just text, this should apply for any and all automations that
send emails from ezbizi."* The warning is an automation (`scheduling.overbooking_found` → email.send →
`AutomationMessage`), and automation email is plain text by a recorded decision: a business's words were
kept out of EzBizi's styling. This reverses that, keeping its reason: business-written text is shown as
text, never as HTML (lists, bold and headings allowed), and the business is named in the band and the
footer. Desktop/phone **1** the sign-in page's navy band, a white card, tiles, days with red "over" and
grey "closed day" pills, one button (recommended); **2** a light report with a cyan rule and one table;
**3** a digest with navy day bars and coloured dots. Touches `resources/views/vendor/mail/` (notifications
and saved reports already use it), `AutomationMessage`, and the overbooking job's payload.

### 38 · Leads · the Templates tab, and a Dashboard tab beside it — **Jose: "as u recommend" — 1, 4, 7 and 10** — **BUILT** `f4101baa` (the Dashboard reads Leads' `GET /leads/dashboard`, contract sent 2026-09-30)

Jose, 2026-09-30, with a screenshot of Manage › Templates: *"see if you can improve the leads template
screen, it looks terrible"*, then *"also make a new tab called Dashboard which gathers info, reply
rates from each user, response time, amounts of leads, graphs, etc"* — *"sub tab for leads i mean,
like with the connections and templates sub tabs"*. Templates today: thirteen company chips in three
rows, each template a card of two boxed fields, codes like `{{greeting}}` in fine print, Save at the
bottom, no preview. **Templates** desktop **1** companies · templates · the open template with Insert
buttons, a live preview and Save on top (recommended); **2** a company picker and a list that opens in
place; **3** the reply box's buttons as the team sees them, edited in a side panel; phone **4**-**6**.
Client only (the PUT already saves a company's list in order). **Dashboard** desktop **7** six tiles,
leads per day by source, first-reply speed, one row per person (recommended); **8** a card per person
with the team beside them; **9** company by company with a week-by-hour arrival grid; phone **10**-**12**.
Needs one read-only `GET /leads/dashboard` from Leads. Measurable today: the person behind each
reply sent from EzBizi (the `lead.replied` audit), not replies sent from A1 Tools or Google's app;
automatic replies are counted apart so they cannot make response times look instant.

### 37 · Leads · a Templates tab in Manage, and a company tag on every lead — **Jose's own shape, built directly** — **BUILT** `bd46c68f`

Jose, 2026-09-30, through Leads: *"inside manage and we can make a connection sub tab and templates
sub tab"* and *"add a tag with the name of the company it belongs to, so this doesn't happen again"*
(chimney prices went to a garage-door lead). No gallery: he named the shape. Manage is one page,
← back, a Connections | Templates strip, both tabs kept alive; on a phone the strip is on the first
step only. **The tag is outlined and the same for every company**, not a colour per company: the
filled colours on a row are already spent on the source (one per source, 2026-09-29) and the status,
and a second colour system on the same line would read as another source. It sits first on the
thread header's source line, and **only there**: it was on each list row as well until Jose ruled,
the same day, *"it's fine on the contact details, we just need it on one place not on both"*
(off the rows in `3d8235fa`).

### 36 · Clock In · the lock screen — **Jose: "go with ur recommendations for the lock layout" — 1 and 4** — **BUILT** `51fb15c1`

Jose, 2026-09-30, with a screenshot of the lock screen: *"the clock in lock screen could also use some
love, show the app logo, etc."* Today: "EzBizi" in plain type, the time, the name, a raw 08:00 – 17:00,
a sentence, the green button and Sign out, small in an empty dark window. `docs/previews/clock-in-lock-layouts.html`:
desktop **1** the sign-in page's dark gradient with one card: app icon + wordmark, large clock and the
date, the person (photo or initials, role), today's shift as a chip in the organization's clock, full-width
Clock In, Sign out, the organization at the foot (recommended); **2** split like the sign-in page, the day
on the left and the person on the right; **3** a phone-style lock: a huge thin clock with the person in a
bar along the bottom; phone **4**-**6** the same three. Client only (everything is in /me); the punch,
poll, window lock and after-hours sentence unchanged. `lock_screen.dart` is Clock In's.

### 35 · Phone · the Dial screen — **Jose: "use your recommendaitons for phone dial" — 1 and 4** — **BUILT**

Jose, 2026-09-30, with two screenshots of Communication › Phone › Dial: *"see what we can do to
improve the Phone Main Screen"*. Today: recent calls are numbers only (the same one nine times in a
row, a customer's number still digits although every call carries `customer_id`), no day groups, no
missed filter; the keypad floats in a wide empty column above the live transcript's grey box, which
sits empty until a call; the number shows raw (+13057242199) in the box and in the Customer panel;
Calling as runs the full width and "Do not disturb" sits alone on a line. `docs/previews/phone-dial-layouts.html`:
desktop **1** a call desk: calls by day, named, repeats folded (×9), chips All / Missed / Voicemail,
"called back" on a missed call; one strip for the state and the line; a name-or-number box that
suggests customers and teammates; a larger keypad; the transcript only during a call; the person
panel as a customer card over one timeline of calls and texts (recommended); **2** conversations,
one row per person with their whole history beside it and the keypad behind a button; **3** the
keypad first, with Call back and Recent under it; phone **4**-**6** the same three. Every option
needs `customer_name` beside `customer_id` on /phone/calls; 2 also needs calls and texts merged per
person. `call_tab.dart`, `call_history_pane.dart` and CallsController are Phone's.

### 34 · Leads · managing connections — **Jose: "go ahead with ur recommendations" — 1 and 4** — **BUILT** `1e8c326e`, `43126b6f`

Jose, 2026-09-30, on Leads › Manage (the Connected sources panel): *"i feel we could also use a revamp
on the manage of leads, see what you can do about it"*. Today one narrow panel stacks Google Ads (22
accounts), Thumbtack and Website; every row carries Name it / Change / Disconnect, certification
problems are loose red lines, Disconnect all sits beside the search. `docs/previews/leads-connections-layouts.html`:
desktop **1** a Connections page in place (← back to the leads): providers on the left with counts and
how many need attention, the provider's accounts as a table (Account, Goes to, Leads, Status) with chips
All / Needs attention / Never sent a lead, the account beside it (name, where its leads go as a search
picker, certification problems listed, Disconnect at the foot), Disconnect all at the table's foot
(recommended); **2** the side panel kept but wider, providers as a switch, one line per account that
opens in place; **3** what needs attention across providers first, provider cards, accounts as tiles;
phone **4**-**6** the same three. Client only: "Needs attention" = certification `attention` or status
`broken`/`revoked`, "Never sent" = `leads_count` 0, all served today. `leads_accounts.dart` and the
Manage section of `leads_screen.dart` are Google API / Leads'.

### 33 · Reports · every saved report — **Jose: "as you recommend" — 1 and 4** — **BUILT** `59665c6c`

From the deploy desk, 2026-09-29, Jose verbatim, on the "Weekly work hours report" rule: *"i should also
be able to seelct which role gets reported or not or perhaps that's something that should show on the
reports module, cause i see that it says on the which report weekly time report, so shouldn-t i be able
to see that existing report on reports ?"* The report is a Work Hours `TimeClockReport` (roles, + / −
people, recipients; `work_hours.reports.view` / `work_hours.manage`), and Reports' library lists only
`SavedReport`. `docs/previews/reports-every-report-layouts.html`: desktop **1** one library of every saved
report, each edited where it lives, a Work Hours one opening beside the list with its week, who is in it
and the rule that sends it, and the automation step saying who the chosen report covers with Edit who is
in it (recommended); **2** the library grouped by module, a Work Hours card linking to its tab; **3** the
Work Hours reports tab moved into Reports, and a rule able to narrow a report to some roles (server:
a roles setting on `send_report`); phone **4**-**6** the same three. Every option needs a `sent_by` per
report (the rules whose active version sends it), which nobody serves yet. Asked Reports and Clock In /
Work Hours which shape they would refuse and who serves it; `automation_builder.dart` is Automations'.

**The owners, 2026-09-29:** neither Reports nor Clock In refuses any shape; both prefer **1**. Reports:
the client calls both lists, each under its own gate (`reports.library.view`, `work_hours.reports.view`),
so a refusal on one never blanks the other; and "No reports built yet" only when both are empty. Clock
In: a Work Hours row only for holders of `work_hours.reports.view` (recipients are addresses, the
preview is built within the reader's reach); **3** would re-key that grant for no gain. `sent_by`
comes from a helper Automations owns, attached by each module to its own rows, with rule names and
schedules only for `automations.view`, otherwise `sent_automatically`. The editor's bottom-sheet
person picker became the search picker in `55b0ae42`.
The helper is `App\Support\Automations\ReportSenders::for(type, ids)` (`f014e72f`): per report, the rules
whose active version sends it, with `disabled_at` so a rule that switched itself off reads as off;
Reports and Clock In each attach it to their own list.
Served: Reports `50f58055`, Clock In `64593d3b` (`sent_by` for `automations.view`, else null; `[]` when
no rule sends it). **Open before the build:** `sent_automatically` differs — Reports counts only a rule
that is on and not auto-disabled; Clock In (`TimeClockReportsController.php:46`, `isset`) counts any
rule with the step, off ones included, so one library would read the same state two ways. Reports'
meaning is the one to keep ("will it go out"); Clock In aligned it in `c3961bc1`.

**Built:** the library in `reports_screen.dart` (table on a desk, cards on a phone, chips per kind), a Work
Hours report in `screens/reports/work_hours_report_panel.dart` (docked at 460, in place on a phone), the
editor shared as `editWorkHoursReport` and the covers line as `workHoursReportCovers` in Work Hours' tab,
and the step's covers line in `automation_builder.dart`. Reports' `root_label` (`3af98941`) names what a
Builder report counts. The schedule in words is `core/schedule_words.dart`.

**A third kind, 2026-10-01** (Jose: the daily "Overbooking warning" belongs in Reports). Asked by Reports
whether it opens Scheduling › Overbooking or a panel: a panel, because option 1 is Jose's pick over option
2's link to the module's tab. `screens/reports/overbooking_report_panel.dart` docks like the Work Hours one
(covers, Sent by, the next 7 days) and draws `OverbookingPane(embedded: true)`: the same endpoint and rules
the morning email uses (`SchedulingWarnOverbooking::DAYS`), so the panel and the tab cannot disagree. The
section and Sent-by blocks are shared as `reportPanelSection` / `reportSenders`. Reports owns the row, the
chip and the dock branch; Scheduling serves `GET /scheduling/reports`.

### 32 · Jobs · estimates — **Jose: "as u recommend" — 1 and 4** — **BUILT** `0bf0313a`

From the Jobs session, 2026-09-29: Jose approved estimates as EzBizi records in Jobs (child module
`estimates`, `sidebar: false`, drawn by the Jobs screen; estimates.* ANDed with jobs.financials;
API in 72b6e46a). `docs/previews/jobs-estimates-layouts.html`: desktop **1** a Jobs | Estimates
switch, the estimates list as the jobs list's twin with the estimate docked and its lines as a
small table (recommended); **2** no list, an Estimates section on the job and the customer that
opens in place; **3** a board of Draft, Sent, Accepted and Declined with the total first; phone
**4**-**6** the same three. Lines replace a total only while draft or sent, and an imported
total-only estimate keeps A1's total until lines are added. Anything in `jobs_screen.dart` goes past
Jobs before it is committed.

**Built:** `lib/screens/jobs/estimates_tab.dart` (EstimatesTab, EstimatePanel) and the Jobs | Estimates
bar in `jobs_screen.dart`, which Jobs OK'd on one condition: both tabs stay alive (Offstage, for
automations_screen's semantics reason) so a look at the estimates keeps a docked job's unsaved
edits, and Estimates is built on its first open. The search is Jobs' `?search=` (93e2c02f). The
estimate panel is a docked `SidePanel`, which gained `onBack`: drawn in place on a phone it has a ←
where its ✕ was, so Android Back finds it. Unsaved edits refuse another row, as on the jobs list.

### 31 · Jobs · money on the list and the job — **Jose: "as you recommend" — 1 and 4** — **BUILT** `505af363`

From the Jobs session, 2026-09-29, under Jose's rulings (money is the `jobs.financials` toggle;
Workiz stays the truth, live and read only; spec `docs/superpowers/specs/2026-09-29-jobs-billing-design.md`).
`docs/previews/jobs-money-layouts.html`: desktop **1** money chips beside the status chips, a
totals line, and three figures with a paid bar first on the job (recommended); **2** four tiles
that are the filter, and the job's money as Workiz's invoice; **3** one money picker with the
totals in a sentence, and one folded money line on the job; phone **4**-**6** the same three.
States: Paid ok, Partly paid caution, Unpaid neutral, Overdue bad (due > 0 and due_on before
the reader's today), Credit info. Anything touching `job_detail_panel.dart` goes past Jobs before
it is committed.

### 30 · Leads · more room for the conversation — **Jose picked 3 and 5** — **BUILT** `0bf4a46d`; the rail's counts wait on Leads' `meta.unread_by_source`

Jose, 2026-09-29, beside A1 Tools: *"ezbizi is taking a lot of space with tabs and filters from
the chat ... not to copy a1tools but to make something in between, better than both"*.
`docs/previews/leads-roomier-layouts.html`: desktop **1** everything for the list in the list
pane, **2** A1's tab bar made ours (recommended), **3** sources down the side; phone **4** tabs
you swipe (recommended), **5** one source button and a sources page, **6** round source buttons
with the filters as tabs. The rows, the conversation header, the bubbles and the reply box are
the same new ones in all six, and they keep the earlier rulings (unread is weight, the dot is
unreplied, ours filled primary, no accent bar). **Needs when built:** an unread count per source
in the list's meta, which is what the tab numbers are; and the phone conversation drawn in place
with its own back arrow, not pushed as a page (containment).

### 29 · Web Management · SEO Helper — **Jose: "as u recommend" — 1 and 4** — **SHIPPED** (`24bb6dce`, 0.0.0303, the desk verified all five platforms)

**Built (client):** `lib/screens/web/seo_helper_pane.dart`, the last section of the SEO strip. The
health card (good / could be better / need work bar, five equal tiles that filter the list, site
issues with **Fix it** when the server sends a fix), then the page list (search, chips, a dot per
page and its first problem). A page opens in the pane with a back arrow: focus keyphrase, SEO title
with its variables shown as words ({Page title} {Separator} {Site name} {Year}) and insert chips,
meta description, each with a meter; the Google preview (desktop/phone, Google's own colours,
sanctioned in the colour ratchet); the live-page line; Checks as SEO / Readability with the other
pages named and openable; Social preview and Advanced folds; Save sends only what changed, Discard
puts it back, and a draft is re-checked 600 ms after typing stops. Read-only without edit access.
**Not as drawn:** on a phone the health and the list are one scrolling screen, and the editor is
one column (preview, fields, checks) rather than folds.

`docs/previews/web-seo-helper-layouts.html`. Asked by Web Management (session 8db30bbf), from Jose,
2026-09-28: *"add it, see if you can improve it as well … a SEO Helper basically"*, never naming the
plugin it replaces the editor of. Server contract at `2c4bc148` (GET `/web/sites/{site}/seo-helper`
[?fresh=1], GET `…/{id}` with analysis, POST `…/{id}/analyze` for a draft, PUT `…/{id}` with only
the changed fields; titles stored raw with `%%title%% %%sep%% %%sitename%% %%currentyear%%`, the
preview shows `final.title`). Desktop **1** health on top, the list under it, the editor in its
place (recommended) · **2** the list and the editor side by side · **3** a list of things to fix,
fixed where they are listed. Phone **4** three screens, the editor in folds (recommended) · **5**
the editor in tabs · **6** one thing to fix at a time. In every option: a section in the SEO strip,
everything inside the pane (containment), the Google preview cut by pixel width and length, insert
chips in words, SEO and Readability checks re-run on a draft, the live-page reading and the
site-wide duplicates made visible, Save drawn only with edit access.

**For the build (Web Management, `a90958e4`):** GET `…/seo-helper` adds `groups` =
`{keyphrase|title|description: [{value, ids}]}`, one entry per value shared by 2+ pages; a page's
`keyphrase_unique` / `title_unique` / `description_unique` issue carries `with: [ids]`; GET `…/{id}`
(and analyze, PUT) adds `shared_with: {keyphrase|title|description: [{id, name}]}` to name and
open them. The "UPDATED 2024" warning is the site's own SEO setting, not EzBizi's Templates: a
`stale_year_*` / `fixed_year_*` site issue carries `fix: {field, value}` and
`PUT /web/sites/{site}/seo-helper/site {page_title_template?, post_title_template?}` applies it
(edit access) and returns the refreshed overview, so the button is **Fix it**, sent as is.

### 28 · Leads · Status, Manage and Mark all read — **Jose: "as u recommend" — 1 and 4** — **SHIPPED** (`6bd2db9b` on the web in 0.0.0304, per the desk)

**Built (client):** `02bbfe60` the thread header: the status a pill after the name, Archive and
Not a lead beside Book on a New lead, Back to New on any other, sharing the name's line when there
is room and under the lines when not; on a phone Book full width with the two moves in one row
under it. Then the list: Connected sources is a box that hugs its words with **Manage** right after
them (the same box on a phone), **Mark all read** is the last item of the chip row after a hairline
("Read all" on a phone), and a row whose lead is not New carries the same pill
(`leadStatusPill`, shared). Built on Leads' `2cbfe4a4`.

`docs/previews/leads-status-controls-layouts.html`. Asked by Leads (Google API session), from Jose,
2026-09-28: *"the manage, mark all leads, it all feels like it's not part of the ui and its like
floating in the air. the status specially needs to be improved"*. Found while drawing: the search,
sources and chips header spans the full width above BOTH panes, so Manage and Mark all read sit at
the far right above the conversation, which is why they float. Status is EzBizi's own pipeline;
43,184 of 43,190 Google leads are New. Desktop **1** beside what they act on: Manage after the
sources in their box, Mark all read after the chips, status a pill and the verbs Archive / Not a
lead beside Book (recommended) · **2** a toolbar box, status as four buttons · **3** status is the
inbox: Inbox / Booked / Archived / Not a lead tabs. Phones **4 · 5 · 6** follow them.

**For the build:** start from `e3b5a623` (Leads' customer card in the thread header, the latest
commit on `lead_thread.dart` and `leads_screen.dart`); keep that card as Leads drew it.

### 27 · Marketing · Social posting that feels finished — **Jose: "as u recommend" — 1 and 4** — **BUILT**

**Built (client, social_media_tab.dart):** desktop 1 — Publish turns the composer into the
accounts resolving ("Posting… 1 of 3 done", a bar filled only by answers), then a mark that
settles and the sentence ("Posted to 3 accounts · Facebook and Instagram are live. TikTok is
waiting in your drafts." / "Posted to 1 of 2" / "Not posted" / "Scheduled for Tuesday 9:00 AM
on 3 accounts" / "Still sending" when the poll gives up), the post shown back, View on Facebook
per account, See it on the calendar and Write another. Every post is on both calendars on its
day (green posted, blue scheduled, red needs a look, the new one outlined), and clicking one
opens a side panel with each account. Phone 4 — publishing takes the pane (ring "2 / 3"), the
finish screen with Write another / Done, and Done scrolls to **Your posts**, an agenda (Today,
Tomorrow, the days ahead, Earlier) replacing both calendars below 600. Also fixed: the composer
column now scrolls — twenty Sent posts overflowed a 900-tall screen by 437 px. **Not built:**
Try again on a failed account (no retry endpoint; asked Meta). The poll stays every 3 s for
about a minute (the existing one), not the 2 s Meta suggested.

`docs/previews/social-poster-layouts.html`. From Jose, verbatim, via the Meta session,
2026-09-28: *"there was no hint that the post had been successful … we need to add flare, loading
screens, success screens … i didn't see anything appear on the calendar even though you posted it
and it succeeded … the user needs to feel like he's accomplished what he set out to do"*. Desktop
**1** the composer becomes the result and the post lands on the calendar (recommended) · **2** a
page of its own: posting ring, then a finish screen · **3** the calendar is the page, composing is
a panel over it. Phone **4** a finish screen, then an agenda (recommended) · **5** progress from
the bottom, the week as a strip · **6** a running feed, newest first.

**In every option:** a per-account posting line that resolves (posted with the network's link,
failed with its sentence and Try again, TikTok to drafts); a success moment that names what
happened ("Posted to 3 accounts", "Scheduled for Tuesday 9:00 AM"); every post on the calendar,
sent green, scheduled blue, failed red, the new one outlined once. Found while drawing: the
calendars are built with no posts passed in (`const _CalendarsColumn()` in social_media_tab.dart),
though `GET /social/posts` already carries the times and per-target status.

**For the build (Meta, 2026-09-28):** POST /social/posts answers at once with every target still
`queued` — production queues one PublishSocialTarget job per target (SocialPostsController.php
246-250); only the sync test queue sees them resolved in the response. So: draw the "sending"
lines from the POST answer, then re-read GET /social/posts about every 2s until no target is
queued, giving up after about 60s (then say which accounts are still pending, not failed). Each
target ends with its status and `url` (filled when the permalink read-back succeeds). No
single-post GET exists; the list is enough, and Meta will add GET /social/posts/{post} if asked.
Scheduled posts dispatch nothing until `ezbizi:publish-due-social-posts` runs: no poll, straight
onto the calendar from `scheduled_for`.

### 26 · Web Management · Hours for several websites at once — **Jose picked 2** ("the numbered steps one"); phone **5**, the same form stacked (he named the desktop only) — **BUILT**

**Built (client):** `lib/screens/web/hours_bulk.dart`, opened from SEO › General with "Edit hours
on several websites" under the hours (edit permission only). Steps 1 Which websites (ticked
chips, the site it was opened from ticked already) · 2 The week (Keep existing · Set hours ·
Closed all day; each edge Keep or a time, × puts it back to Keep) · 3 Convert hours from and
Don't open before. Review replaces the form: a tab per website with its count ("la… · 5",
"… · blocked"), Day · Now · Requested · Converted · Final and the note (no change / not before
6:00 AM / the server's error in red); a blocked site says nothing is written to it and why.
Update N counts only websites that will change; a 409 returns to the form with the server's
sentence. Results list every ticked website, the untouched ones included. Phone: day cards
with Keep · Hours · Closed, the note above the bar, Edit instead of Back to the form, review as
cards. **Not as drawn:** seven day rows, no "Mon – Tue" grouping; Google Business Profile is in
the step's note, not a greyed chip.

`docs/previews/web-hours-bulk-editor-layouts.html`. Asked by Web Management (server live in
0.0.0290, `c77a7873`: `POST /web/hours/review` and `/apply`), from Rodrigo's brief via Jose,
2026-09-28 (the brief is on Jose's desktop, `ezbizi-hours-editor-feature-brief.md`). Desktop
**1** one page, review docked beside the week (recommended) · **2** numbered steps, review as a
tab per website · **3** a grid, days down and websites across, final hours live. Phone **4** a
page per step, a day from the bottom · **5** one scrolling form, day cards, Review on a bar that
stays (recommended) · **6** by days: pick days, then what they do.

**Fixed in every option, from the brief and the contract:** one control per day (Keep existing ·
Set hours · Closed all day), no switch; under Set hours each edge is Keep or a time; a closed day
needs both. Websites are the only live destination — Google Business Profile drawn greyed with
the server's reason. Convert hours from (IANA, friendly label) and Don't open before (after
converting, opening only). The main button is Review changes; Update N websites applies that
review's token, and a review over 30 minutes old is refused (409). Results: Changed · Unchanged ·
Blocked · Failed · Not confirmed.

### 25 · Jobs · a job's service area — **BUILT: Jose picked 1 and 4** ("as you recommend")

**Built (client):** the table's Franchise column became **Where** (`WhereLabel`: "Illinois ·
Chicago", the area alone when it repeats the franchise, "Ohio · Not placed" in amber); the
Franchise picker became the **Where** picker — Anywhere, Not placed yet (with the count), each
franchise, and "Franchise · Area" — mapping to `franchise_id` or `service_area_id` (`none` for the
unplaced). The job record gained a **Service area** field (only where the franchise has areas,
saved with Save, part of the unsaved-edit check) and the amber mark beside its status. Phone rows
carry the area in the second line.

**"Not placed" only where there is somewhere to place it.** The mark needs the franchise to have
areas in `meta.service_areas`; an older server, or a franchise with none, shows the franchise
alone. Without that, every row of an organization with no areas would have turned amber and the
mark would have meant nothing. Server half (`none`, `meta.service_areas`, `meta.unplaced_count`)
is Jobs', to the spec sent with the pick.

**The drawing, as he saw it:**

`docs/previews/job-service-area-layouts.html`. Asked by Jobs (API `4f82c62f`), from Jose,
2026-09-25: *"Service Areas — sub divisions within the franchise"*. Desktop **1** one "Where"
column and one "Where" picker · **2** an Area column, and a franchise's areas as chips · **3**
"Not placed" as a queue of its own. Phone **4** the area in the second line · **5** the area on
top · **6** a banner for the unplaced.

**Measured on the box before drawing:**

- **33 areas across A1-Group's 9 franchises**, 1 to 11 each (Nationwide's eleven are cities).
  The ORGANIZATION is "A1-Group"; "A-1 Chimney" is its company — a filter on the org name
  "A-1%" returns nothing, which is how the first count read zero.
- **Three areas share their franchise's name** — Connecticut, New Jersey, New York. So a
  combined label draws the area alone when it would repeat ("Connecticut", not "Connecticut ·
  Connecticut"), by the settled "a second badge must say something the first does not".
- **Longest name: "Los Angeles / Orange County"**, 27 characters — drawn, because it is the one
  that decides whether a combined column fits.
- **No job was placed when this was drawn** (0 of 8,550). *Since:* the backfill ran at 13:58 UTC
  on 2026-09-25 — 7,645 A1-Group jobs placed, 448 live jobs left empty on purpose (retired names
  like "Seattle", 105 of them; call-centre tags pointing at another franchise's area).
- **Nothing recorded WHO placed an area** when this was drawn, so no option draws provenance.
  *Since:* `b309bb80` added `jobs.service_area_chosen_at` — null when the machine placed it, a
  time when a person changed it (clearing included). Only a person's choice is final; a Workiz
  rename still moves a machine-placed job. Not in the payload, and Jose's pick does not need it.

**Every picker drawn is the app's own** (`SearchPickerField`: a searching list over the page),
not a menu hanging off a field — the first render drew one, against the 2026-09-17 ruling,
and it was redrawn before this went to Jose.

### 24 · Jobs and Customers · how the lists look — **BUILT: Jose's combination, drawn as 7**

**Picked 2026-09-24**, in his words: *"i like the table view, but it would be nice to also click
and have it change to sidebar and detailed view, and also have the time divisions"* — then
*"the canceled tab should be red, not gray"*. So: option 1's table, option 2's docked record,
option 3's headings; the phone follows as 4's rows under 5's headings. Drawn as option 7 in the
same file before building, so the record of what was built is a picture and not a paragraph.

**Built:**

- **Jobs** — a table under day headings (Today / Tomorrow / Yesterday / the weekday; each with
  the server's count for the WHOLE day, `meta.day_counts`, so a page that stops mid-Thursday
  still says "38 jobs"). **Upcoming · Past · All**, opening on Upcoming, which is soonest first
  from midnight in the reader's date. Status chips count within the window and double as the
  legend. Times are the franchise's wall clock, the same the job's panel shows.
- **Click a row** and the table becomes a 380px list with the job docked beside it
  (`SidePanel.docked`); × puts the table back. **Switching rows with an unsaved edit is refused**
  with a snackbar — in this layout the next row is one click away, and that click would
  otherwise discard the edit without a word.
- **Customers** — the same, under letter headings; City and the Repeat / No jobs yet chips; the
  docked record now lists the customer's jobs, and says how many sit in franchises the reader
  cannot open rather than letting the count look wrong.
- **Phone** — two-line rows under the same headings; a tap opens the record over the list.
- **Colour** — `Tone` + `ToneColours` in `theme.dart`, mapped from the status VALUE with a
  neutral fallback (`toneForJobStatus`). Canceled is red in both themes; `tone_colours_test`
  measures all twelve pairs at ≥4.5:1 and caught dark-mode red at 0.31 saturation — a pink.

**Deliberately not built:** sorting by column — the headings ARE the order, and a table sorted
by amount cannot also be divided by day; per-letter counts on customer headings — the database
collation files "Ángela" under A and a count would have to agree with it exactly; call and text
buttons on phone rows — nobody asked. Each is a small addition if wanted.

**The first drawing, for the record — options 1–6 as he saw them:**

`docs/previews/jobs-customers-list-layouts.html`. Jose, 2026-09-24, with a screenshot of each:

> *"i'd like to change the view of how jobs and customers are displayed, see what you can come up
> with"*

Desktop **1** a table · **2** the list beside the record (HR Records' shape) · **3** grouped by day
and by letter. Phone **4** two-line rows · **5** pinned headings · **6** start from a count.

**Measured on the box before drawing, because the screenshots mislead about the data:**

- **Status is the most informative field, not a constant.** Every row he photographed said
  *Submitted*, but that is the top of a date-descending sort. This organization's 8,152 jobs are
  Pending 520 · Submitted 1,085 · In progress 801 · Done pending approval 1,997 · Done 2,256 ·
  Canceled 1,493 — the six sum exactly to the header's total. So every option gives status its own
  colour and counts it.
- **The default order puts the least relevant jobs first.** `JobsController::index` sorts
  `COALESCE(scheduled_for, created_at) DESC`, so jobs booked months ahead (April 2027, in his screenshot) head the list
  while ~560 in the next seven days sit below them. Options 1, 2, 4 and 6 add an *Upcoming · Past*
  control that opens on today; 3 and 5 group by day. **Any of these REORDERS the list** — say so in
  the build, per the grouping rule above.
- **No technician column, deliberately:** `performed_by_user_id` and `assigned_role_id` are null on
  every one of the 8,177 jobs. A column that is empty for everybody is chrome.
- **Two-thirds of jobs have no amount** (2,679 of 8,177 are non-zero). Drawn as a muted `—`, not
  `USD 0.00` — a missing measurement is not a zero one.
- **Customers:** city is filled on 7,584 of 7,688 but is NOT in the list payload (`present()` only
  sends the address when `detailed`), so the City column is a two-field server change. 82% have
  exactly one job; 885 are repeat customers and 502 have none, which is what the *Repeat* and *No
  jobs yet* chips count.
- **The owning franchise stays on every customer row, company included** — `customers_screen.dart`'s
  docblock gives the reason (a search escapes the view scope, and two companies may each have a
  *Florida*). Drawn with the company muted so the franchise is what reads.

**Not in any option, and why:** call/text buttons on phone rows (not asked for), and per-status
colours for statuses an organization adds later — the build must map by the status VALUE and fall
back to neutral, never guess a colour for a label it does not know.

### 23 · Settings · Integrations, as cards — **DRAWN, awaiting Jose**

`docs/previews/integrations-layouts.html`. Jose, 2026-09-22:

> *"I want to revamp the Integrations Screen, i'd like to have cards instead each with their own
> screen for connections, etc, something like the second image as an example but with our style of
> course. Remember to take into account org - company - franchise ownership, etc."*

Announced by Settings before building it, which was the right call and the reason this is drawn
rather than shipped: *"a grid of provider cards"* and *"a per-provider screen"* each name a NEW
thing, so it is design by the test in this file, not craft.

**THE CAUSE IS SMALLER THAN THE SYMPTOM, and it changes what these options cost.** The screen
scrolls four pages because A1-Group has 53 Google Ads accounts, but what makes it *answer nothing*
is that every row says the same words. `scopeWord` (`integrations_screen.dart:434`) maps a scope
TYPE to a phrase — "this company", "this branch". **The identity never reaches the client at
all**: `SocialConnectionsController:92` already sends `scope_id` and `_Connection.fromJson` drops
it. So "grouped by named company" is one field in a parser and no server change. `last_reached_at`
is sent and dropped the same way.

**THE STATUS LINE IS THE REAL DECISION, NOT THE CARD SHAPE.** Settings proposed *"53 connected"*.
That is a count with no subject — the same failure as "this company", one level up — and
nobody opens this screen to be told things work. `status`, `status_reason` and `last_reached_at`
are all on the wire, so a card can say **"1 of 53 needs attention"**, which is the question people
arrive with. Every option leads with what is wrong when anything is, and falls back to the plain
count when nothing is.

**Five states on the switcher, because a layout that only reads at "53, all fine" is a layout for
one afternoon:** 53 healthy · 1 of 53 broken · nothing connected · a branch-level
reader · one of each.

**Desktop 3 is deliberately not a card grid.** This screen's own docblock says it exists because
*"what is this company connected to"* was not answerable. Cards-by-provider answer *"who uses
Google Ads"*. Those are different questions and only one is the written one, so option 3 groups by
PLACE and lets him see both readings before choosing.

> **NOT SETTLED, AND SETTINGS OWNS IT:** which scopes may hold which provider, and what a
> branch-level reader may see of a company-level connection. State **D** draws a guess (a branch
> reader sees only their own). If the rule differs, the drawing changes and the layout does not.

### 22 · App-wide · Popup dropdowns → search pickers — **DONE, AND PINNED AT ZERO**

Jose, 2026-09-17, on a twelve-name person dropdown running off the bottom of the window:

> *"this sort of popup dropdown are terrible ui design, anything that offers users should be a search
> bar picker instead. So i want you to go and find every single use of this sort of dropdown in the
> app and replace it."*

**`client/lib/widgets/search_picker.dart`** is the replacement. `SearchPickerField` shows the search
box only when the list is long enough to need one (`searchFrom`, default 8), which is what makes
"every single use" uniformly right: some of these offer twelve people, some offer *Everyone at once /
In order*, and a search box over two options asks somebody to type where they could have pointed.

**Every `DropdownButtonFormField` and every bare `DropdownButton` in the app is gone.** The nine
remaining `DropdownMenu`s all set `enableFilter: true` — they are already a search bar picker
wearing Flutter's name for one — and are deliberately left.

`client/test/no_popup_dropdown_test.dart` holds the count at zero, because "every single use" decays:
the next form somebody writes reaches for the control Flutter documents, not the one this app chose.
It matches a USE (`DropdownButtonFormField<` or `(`), never the bare name, which appears in
search_picker's own explanation of what it replaced — **a guard that read the word would go red on
the prose describing its own subject, and the cheapest fix for that is to delete the explanation.**

> **THE TRANSFORMER, AND THE FOUR TIMES IT GUESSED.** Hand-anchoring on whitespace failed twice on
> indentation, so the job became a script that balances brackets:
> `<scratchpad>/swap.py` (session-local; rewrite it rather than hunt for it). It was right about
> roughly seventy files and wrong about seven, in four separate ways, and the pattern in the four is
> worth more than the script:
>
> 1. `Text(x, overflow: ...)` — the regex stopped at the only closing paren, so the second argument
>    became part of the label. ~40 files, full revert.
> 2. A `//` comment the splitter could not see, and an `items:` that was not a literal list. ~40
>    files, full revert.
> 3. `Text('${_money(x.toInt())}')` — the non-greedy capture stopped at the paren INSIDE the string
>    interpolation. The guard added after (1) asked whether the captured label held a top-level
>    comma; a TRUNCATED label holds no comma, so the guard waved it through. **The guard was aimed
>    one shape beside the thing that breaks.** It now requires the capture to be *balanced*, which is
>    the property the comma check was assuming and could not establish.
> 4. **The dangerous one.** `find_calls` skipped strings but never learned about comments.
>    `numbers_pane.dart` has `// The chosen item's text can grow` above three of its four dropdowns;
>    the apostrophe in *item's* opened a string that closed four fields later, so the call's "end"
>    landed past two whole widgets and they were deleted with it. **`flutter analyze` was clean** —
>    what remained compiled perfectly. Two settings had simply stopped existing on the screen, and
>    the only thing that said a word was `phone_screen_test` tapping *"Nobody — go straight to
>    voicemail"*.
>
> **A green analyze is evidence that what remains compiles. It is no evidence at all that what was
> removed was meant to go.** Every mass rewrite from here needs a count of the thing it claims to
> preserve, not a compile.
>
> Nothing broken ever reached a commit, and every file was verified to contain my own
> `SearchPickerField` before being reverted — checked, not assumed, in a tree ten sessions share.

**What the control gained on the way, each because a real call site needed it and not in advance:**
`helperText` / `helperMaxLines` (several dropdowns explain what a choice MEANS), `border` and
`isDense` (so a swapped field keeps its frame), `errorText` (the automation field picker NAMES a
stored value it cannot offer instead of going quietly blank), a nullable `value` (ten lists offer a
row that means *nothing chosen* — "Not set", "Nobody has the keys" — which somebody picks on
purpose), and an optional `child` for a richer row, where `label` stays required because **a row with
an icon in it has no string to filter on, and a picker filtering on the wrong words looks perfectly
correct while hiding the thing somebody typed the name of.**

**Ten other modules' test files drove the old control** — tapping `DropdownButtonFormField`, reading
`.items`, reaching through the FormField for the `DropdownButton` underneath. They were pointed at
the new control and kept asserting the same things: an option's `enabled`, the order of the scopes,
the five kinds of question the server declares, the disabled "already onboarding" row, the Workspace
directory that stays unlisted until somebody searches it.

**One judgement made without him, flagged in his three bullets:** short fixed-enum lists get the same
control with no search box rather than a search box over two options. If he would rather every one of
them carried a search field, that is a one-line change to `searchFrom`.

### 21 · HR · PTO balances — **SHIPPED** (`477419fe`, HR)

> **THE STATUS ABOVE SAID "awaiting Jose" UNTIL 2026-09-17, AND THE ENTRY'S OWN NEXT
> PARAGRAPH SAID HE HAD APPROVED IT.** A heading contradicted by the body three lines
> below it, on a screen that was built and in front of him. Corrected by reading the
> tree rather than the file: `client/lib/screens/hr/pto_tab.dart` exists and
> `hr_screen.dart` mounts it.

`docs/previews/pto-balances-layouts.html`. Approved by Jose 2026-09-17 — *"PTO Balances is good we
should add that to Human Resources Tab"* — from a real production suggestion. Toula Elwell at
A1-Group, 2026-09-08, her spelling kept:

> *"a part for us where we can see howq much paidf pto we all have and when it can be used"*

**Two asks in that sentence, and the second is the hard one.** *"we ALL have"* is a view across
people rather than self-service. *"when it can be USED"* is a waiting period rather than a number
— so on a screen called balances, **the date is often the answer and the number is the
decoration**. Every layout gives the date its own box rather than a footnote.

> **THE EMPTY STATE IS THE COMMON STATE, and that is what shapes all six.** HR measured
> production: **one of eleven contract templates mentions PTO, the other ten are silent**. So
> *"this organization has not set up paid time off"* is probably the FIRST thing most people ever
> see here. *(Their count is of tenant rows and is relayed — I verified the models and the
> employment-events migration they name, not the eleven.)*
>
> Two consequences drawn rather than described. **State A is organization-wide, not one quiet
> row** — I drew it per-person first, which made the common case look like an edge case in my own
> gallery after I had written the warning saying it was not. And **the remedy belongs to somebody
> else**: it is set on a contract template, so state A says who can change it instead of offering
> a button most readers cannot use.

**The five states are switchable on every layout**, because a layout that only reads well at "6
days available" is a layout for the rarest case. **D is the one worth clicking**: nobody on the
books before the employment-events table has a start date, the migration deliberately refused to
invent them, so eligibility is genuinely unanswerable for those people. It says so. Being told you
may book when your contract says otherwise is worse than being told we cannot tell.

**Recommendation: 3 and 6.**

**3 is the table**, and it is the only layout that already has a column for the number nobody can
compute yet. Nothing in EzBizi records a day off — HR measured that too — so whether a balance
can say "2 taken" is undecided and sits between Scheduling and HR. **Taken** is drawn as an em
dash in every row: present, empty, honest. The day that is settled the column fills and nothing
moves, which is the constraint HR asked me to design for. It is also the densest, sorts by "when",
and answers "who becomes eligible in January" at a glance.

**Its cost is state A, the common one** — five columns of em dashes is a lot of furniture around
one sentence. So in state A the table is **replaced wholesale** by the notice rather than drawn
empty. Worth knowing that my note claimed this before the drawing did it; caught by reading my own
prose back against my own screen.

**2 is the one worth arguing for, and its case is weaker than I first wrote it.** A card per
person puts the states side by side. I argued that from *Sam-no-policy beside Jon-no-start-date
beside Maria-waiting* — and **HR corrected it: all four PTO settings are organization-scoped, so
"no policy" empties every row at once and is never one person's state beside somebody else's.** A
third of the mix I was selling cannot occur, and my fixture drew it anyway until they said so. Not
checkable from here (their settings block rather than a tenant row, and not on main yet), so
relayed — and it **strengthens** the recommendation rather than weakening it.

**Where this leans next, and why it makes 3 safer still.** A-1's paperwork is per-ROLE rather than
per-company — that is what "one of eleven" means, and the one is Project Manager. So a per-person
override over the organization default is likely the next thing, and then a roster genuinely does
show six days beside zero beside two. The table takes that without moving; a card wall would want
re-reading.

**6 on the phone**: one list, you inside it rather than above it, each row carrying its state in
words. It is the only phone option where *"we all have"* is the whole screen rather than a section
of it, which is her phrasing. **5 hides the across-people view behind a tab**, which is the ask.

### 20b · HR · Work Hours, the drawing — **SHIPPED** (`70c1331c`, and changed since)

> **SAID "awaiting Jose" UNTIL 2026-09-17.** Built as `work_hours_tab.dart`, then
> changed again on his word the same day (`3d4ce8a8`: newest day first, future days
> hidden). So the status was not merely stale — it was stale through a second round
> of his feedback, which is the state a queue is least able to survive.

Companion to **entry 20 above**, which is Clock In's brief and holds Jose's requirements verbatim. Numbered 20b rather than 20 because two headings with one number is how a reader ends up quoting the wrong half.

`docs/previews/work-hours-layouts.html`. Brief from the Clock In session.

> **A WARNING THAT WAS TRUE FOR ABOUT A MINUTE, LEFT HERE BECAUSE THE HABIT IS WORTH MORE THAN
> THE TIDINESS.** This entry said the brief was not on main. It was not, when I grepped: `c97efb35`
> was still only on `session/clock-in`, and I read it with `git show` to draw from. The deploy desk
> merged it minutes later, in the same minute my own commit is stamped. **So the claim was stale
> rather than wrong** — which is the failure I have corrected in four other rows this week, arriving
> in mine. The brief is at line 1918 and both entries survived the merge with nothing dropped.
>
> The predicted conflict was real and the desk resolved it keeping both sides. That part of the
> warning earned its place.

**Already built by Clock In (`e5c3ecff`) — do not redo:** the rename to Work Hours (en + es), the
tab moved second beside Dashboard, and the whole data layer
(`GET /api/v1/hr/time-clock/week?on=YYYY-MM-DD`). The old one-day list still renders on that tab;
replacing it is the build.

> **HIS LIST CONTAINS TWO DIFFERENT SCREENS, AND THAT IS THE DECISION.** His first bullet asks for
> *one expanding row per user* — a single list you open in place. His last asks for *a left
> sidebar of users with the week beside it*. Both are reasonable, they answer different questions,
> and building one does not give you the other. Options 1–3 are the three honest readings.

**Recommendation: 1 and 5.**

**1 is the only option that satisfies BOTH bullets.** The sidebar is one row per person — which
is his first complaint, that he appears several times today — and selecting somebody shows every
clock in and out grouped by day, which is the expansion he asked for. 2 and 3 each satisfy one
bullet and not the other. 1 also builds his last sentence literally: search on top, avatar before
the name, total/days/average, the week he is in.

**3 is the one worth arguing for anyway.** It is the only layout that answers *"did everybody do
their hours"* without clicking six people, which is the Friday question, and the only one showing
the SHAPE of a week. Its cost is his first bullet: a cell holds a total rather than the punches,
so reading the actual times needs a second layer.

**5 over 4 on the phone, and it is a deliberate break from pairing.** A sidebar on a 380px screen
is just a list, so the two-pane idea buys nothing there — while expanding in place is what every
other list in the app does, keeps the totals on screen while you compare, and is his first bullet
natively. 4 exists if matching the desktop shape matters more than that.

**Drawn to respect four facts from the brief rather than invented:** everybody in reach is listed
**including people who worked nothing** (greyed, never dropped — a missing name reads as "not on
the team"); an open shift reports time **so far** and is the green indicator; times arrive
**already formatted** in the organization's clock, so nothing formats from the device; and Sunday
is a **default rather than a rule**, so the header reads `starts_on`.

**Week navigation is not on his list and every option draws it.** The tab this replaces has day
arrows, so shipping without it would remove something he has today. Worth a word from him if he
would rather it went.

**One thing to ask Clock In rather than compute here:** option 6's team numbers — who is on the
clock, who has no hours, the team total — are not in the payload. Summing them client-side is a
second place that can disagree with the server the day the rules change. If 6 is picked, that is
a request to them, not arithmetic here.

### 19 · Communication · Editing from the call flow — **SHIPPED** (`96ae6847`)

> **SAID "awaiting Jose" UNTIL 2026-09-17.** `call_flow_edit_drawer.dart` hosts the
> real Settings panes, which is the *"every settings needs to be synced and manageable
> from here as well"* he asked for. Since corrected twice more — the breadcrumb now
> reads the rail (`c4db5946`), and the chart re-reads itself after a change
> (`1bbcb938`).

`docs/previews/call-flow-sidebar-layouts.html`. Jose, 2026-09-16, verbatim:

> *"if i were to click on the ring group at the top as u can see in the image, it should open a
> right sidebar with the list of members, so i can assign - unassign, so basically, **every
> settings needs to be synced and manageable from here as well**"*

**This is the ask parked on 2026-09-15 as entry 18b’s option B, and he has now made it twice.**
The scope question is therefore settled by him rather than by me: the chart becomes somewhere you
change things. Parking it was right and unparking it is his call, not a reversal of mine.

**The hinge is the second half of his sentence.** *"A list of members I can assign and unassign"*
is one editor. *"Every settings manageable from here"* is fourteen — Phone stamps every node with
`edit: {pane, id}`, where `pane` is a real section id out of `PhoneSettingsTab._sections`
(`ring-groups`, `voicemail`, `hours`, `flows`, `messaging`…). **Whether the drawer HOSTS those
panes or REIMPLEMENTS them is the whole decision**, and the three answers age very differently.

> **Do not derive the pane from `kind`.** Phone's warning, with a test pinning it: a voicemail BOX
> and a voicemail OPTION on a menu are both kind `voicemail` and are edited in two different
> panes. The ring group node and its queue node deliberately share one target, because
> queueing-versus-voicemail is the group's own `overflow` setting.

**Recommendation: 1 and 6.**

**1 hosts the real pane** and is the only option that cannot drift. There is one ring-group editor
in the app; add a field to it and the drawer has it the same day. It is also the only one that
delivers the second half of his sentence rather than the first. Its cost is honest and worth
saying out loud: those panes were drawn for a full settings tab, and this is a commitment to
making fourteen of them survive a 372px column.

**2 is the nicest to use and the one that goes wrong quietly**, which is why it is not the
recommendation: the day somebody adds a field to ring groups in Settings, a bespoke drawer shows
yesterday's fields and **nothing fails**. Fourteen editors to write and fourteen to keep in step.
**3 is 1 with a live landing page** — genuinely good, and it lets the narrow-pane work happen per
pane rather than all fourteen before anything ships; its cost is a screen you did not ask for on
a node you open twenty times a day.

**6 is the phone answer** because that view is already a list, and a list that expands in place is
what every other list in the app does. It is also the only mobile option **where the chart never
goes away while you change something** — which matters most for the change he actually named:
taking somebody off a rota and seeing what it does to the rest of the line. **5 is the safe
choice** if the settings panes turn out to need full width, since it gives them exactly the width
they already have in Settings and nothing needs redrawing.

**Phone has offered to put anything else on a node** — permissions, a short label, whether a node
is editable at all — rather than have the client derive it. So a layout needing a fact it does
not have is a question, not a blocker.

### 18b · Communication · Call flow, after Jose saw it live — **A CHOSEN, B PARKED**

Jose, 2026-09-15, on the running chart: *"looks amazing"*, with five asks. Two were Phone's data
and are fixed in `5c033dda`: **one node per GROUP rather than per key**, so a second key reaching a
team is a second arrow into one box; and **a queue per queueing group**, which answers his actual
question — *"the everyone busy line seems to be coming out of last 5th button but in reality it
applies to the entire group"*. It is per group, and the chart now says so.

**SHIPPED in `c91caf98`:** *"should be white on white mode, dark on dark mode"*. The canvas was
fixed-dark on purpose — n8n is dark in both, and a canvas is a drawing rather than a page — and he
ruled the other way after seeing it running.

> **A consequence of Phone's fix that neither of us flagged.** One node per group means two keys to
> one team are two edges with **identical endpoints**: two wires on the same curve, two labels at
> the same point. That is the label collision from his first screenshot, arriving from the opposite
> cause. Parallel edges are now **one wire carrying both keys** — *Press 2 · No key pressed* —
> which is also the truer sentence. Phone, plainly: *"my one box per team fix moved the collision
> rather than removing it"*.
>
> **No group container, and the reason is the new data rather than taste.** With one node per group
> and one queue each, a box around group-plus-queue restates the only pair of edges between them.
> The drawing that confused him was the one with two boxes for one team, and that is gone. If a
> group ever owns more than its queue, a container earns its place then.

**THE TWO LARGEST ASKS, and he should pick ONE.** Half of each is the worst outcome.

**A · Live calls on the chart** — *"see Live Calls flowing through the Node as well"*.

Phone measured Twilio rather than reasoning about it. `current_size` on a queue is an exact live
count of waiting callers, `average_wait_time` comes with it free, and the queue name maps
one-to-one onto the new `queue-<groupNodeId>` nodes. So the offer is: **exact counts on queue
nodes, a ringing-or-connected count for the line, and deliberately nothing anywhere else.**

Nothing else is derivable. A call in the menu, a call ringing a group and a call holding are all
`status = ringing` on the row, and Phone declined to add a per-step column on purpose: the existing
status already goes stale when a final callback never arrives, which is why the pickup list carries
a two-minute ceiling. Their sentence, and it is the right instinct: *"a tile reading 2 calls here
about a call that ended on Tuesday is worse than a tile reading nothing."*

One thing to build around: **a queue resource exists only once it has been used**, so that account
has three queueing groups and one queue. **Absent means zero, not unknown** — the tile reads 0 and
never dashes, and no group is missing a count.

My shape: a count on the tile, and a brighter pulse on the wires leading out of it. **Not** a dot
travelling along a wire — that implies we know the transit when we only know the state.

**B · Editing from the chart** — *"it would also be nice to be able to change things from here,
like clicking one of the nodes gives you the available options"*.

**This reverses the read-only scope he set with "shows me"**, so it is a scope decision rather than
a feature request. *"Click a node and change which group answers"* and *"click a node and edit the
IVR prompt"* are very different amounts of work, and the difference is the whole question. It needs
its own design round and its own gallery rather than being bolted onto a viewer.

**Recommendation: A.** Small, exact where it matters, invents nothing, and it makes the chart
answer a question the list cannot — *is anybody waiting right now*. B deserves to be designed
rather than appended.

> **DECIDED 2026-09-15: A is being built. B is parked and goes back to Jose as its own round.**
>
> He is away for the day and his standing word covers it — `docs/ACTIVE-CONSTRAINTS.md` §11,
> relayed by the deploy desk: *decide as you would recommend, finish end to end, a question
> addressed to him is not a reason to stop*. Only third-party sign-ins, spending money, and
> what-the-business-wants questions actually need him. **B is arguably one of those** — it changes
> what the screen is FOR — which is the reason it is parked rather than started, not deferred out
> of caution.
>
> **A correction I made to my own ask before Phone built it.** I had said "one API call per line"
> as though that were free. Thirteen lines polled every ten seconds is thirteen Twilio calls every
> ten seconds, forever, for a pane that shows **one line at a time**. The endpoint takes the line:
> `GET /phone/call-flow/live?line=<id>`, returning `on_line` plus a map of queue-node ids to
> `waiting` and `average_wait_seconds`. A node absent from the map renders **0**, never dashes,
> because a Twilio queue resource exists only once it has been used.
>
> **The one thing I asked Phone for rather than assumed:** what the payload says when Twilio is
> slow or down. *"No queue is holding anybody"* and *"we could not ask"* are different facts, and
> the tile must not draw the second as the first.

### 18 · Communication · Call flow as a chart — **SHIPPED. Jose: 1 and 6**

`docs/previews/call-flow-chart-layouts.html`. Jose, 2026-09-15: *"a new tab on Settings that
shows me the Call Flow… more graphic / Call Flowchart / a graphic view, where it shows the
current state and separates into nodes like n8n / from left to right or up to down / and begins
with Caller ---- (what happens)"*. A NEW visual pattern, so it came through design rather than
being built directly. Beside the existing Call Flow pane, not replacing it. **Read-only** — he
said *shows me*, and an editable canvas is a different and much larger thing.

**Phone built the data first, so these are drawn against the real shape.** `GET /phone/call-flow`
carries `graph: {nodes, edges}` additively per line; `TwimlRenderer::graph()` builds it.

> **THE PICTURE IS NOT WHAT EARNS THE SCREEN. THE TWO STATES ARE.**
> - **`dead`** — a caller *can* reach it and nothing useful happens: an option pointing at a
>   number nobody set, a ring group with nobody in it.
> - **`skipped`** — ~~configured and **never reached at all**~~ **a step the caller passes
>   straight THROUGH, which plays nothing.** Struck 2026-09-15: Phone gave me that sentence,
>   then read their own renderer and found it emits the opposite — the node is linked in as
>   `arrival → menu → answer`, so a caller does traverse it. Left visible rather than deleted
>   because I built a test fixture to the struck version, and an orphan is a graph their
>   contract cannot produce. The rest of their sentence stands and is still the argument for
>   the tab: *"callers sail past and hear nothing, and nobody complains because the caller does
>   not know they missed a menu and the owner does not know theirs is skipped."*
>
> **A flowchart that draws a dead branch identically to a live one is prettier than the list and
> less useful.** So every option is judged on whether those two are visible *without reading node
> by node*, which is why each carries a count at the top rather than relying on the drawing.

> **REDRAWN THE SAME DAY AGAINST THE THIRTEEN REAL LINES.** Phone did not trust their own
> fixtures — *"my tests build their own fixtures, which only ever prove self-agreement"* — and
> read production instead. Two findings changed what the options optimise for:
>
> - **Not one line has an opening-hours schedule.** Supported, renders, and absent everywhere. My
>   first three drawings leaned on `hours` as the early branch that establishes the rhythm, so
>   every one of them opened with a column that is empty on every line Jose will actually click.
>   **The first branch he sees is the menu**, and that is what is drawn now.
> - **The cycle is the common case, not the edge case.** Eleven of thirteen lines have
>   `overflow = queue`, so `queue ⇄ ring group` is what the tab looks like *normally*. My note
>   that the back-edge is "drawn and not followed" was written as a defensive detail; at 85% it
>   is load-bearing, and the options are now judged **with** it rather than without.
>
> **Both sizes are switchable in the gallery**, because the other two lines are four nodes — and
> a chart that looks broken at four nodes is reporting the truth, while one that only reads well
> at eleven is tuned for a line that does not exist. The bare pair are spare numbers with **zero
> inbound calls ever**, which Phone checked before reporting them as a fault; they are the
> minimum graph rather than a problem.

**Facts the layout may rely on, measured rather than assumed.** Exactly one entry node, always
`caller` — which makes his "Caller ---- (what happens)" literal. Every node reachable and no edge
dangling, pinned by a sabotage-checked test, so no defensive drawing for orphans. **It can
cycle** — `queue → answer → queue` and a "play the menu again" key are real, so a naive walk loops
and a layered layout needs a back-edge *drawn rather than followed*. Six nodes without a menu,
about eleven with a four-option one: **nothing here needs virtualising.** Thirteen lines possible,
so every option carries the line picker the existing pane already uses.

**Recommendation: 2 and 5.** The spine runs down and only branches go sideways, so the first
thing read is the ordinary path — which is the question somebody opens the tab with — and adding
a menu option lengthens a column rather than widening the chart, which is the direction a
settings pane has room in. **5 is the same drawing at phone width**, so it is one shape to learn
rather than two; its collapsed branch row carries *"2 of them are dead ends"* so a branch nobody
opened still reports the thing the screen exists for.

> **WHY THE COUNTS SIT ABOVE THE DRAWING, AND NOT IN IT.** Phone, 2026-09-15, after the
> correction below — the sharpest thing either of us said about this feature, and recorded
> here because the next person to propose folding the counts into the chart will not have
> been in that conversation:
>
> **`skipped` is the only state a picture cannot show by colouring a node, because the node
> works.** It is the flow *through* it that is empty. `dead` colours fine — the caller
> arrives and stops, so the box is where the fault is. A silent step is a working box on a
> working wire between two working boxes, and the whole line draws clean.
>
> The third gallery case demonstrates it: **no red anywhere on that screen.** If the counts
> move into the drawing, that state becomes invisible again — which is the state the tab was
> built for. Anybody wanting to move them has to answer this first.

**A third gallery case, added 2026-09-15 at Phone's request** — *a step that does nothing*,
drawn on options 1 and 6 only, since those are the two built. Zero production lines have one
today, so there is nothing real to look at, and waiting for one means waiting for the day
somebody edits a flow badly — which is exactly the day nobody is watching, because the state
produces no complaint. The gallery is therefore the only place it can be seen before it matters.
**It draws in the chain, third of five**, between Arrival and the ring group. Note what that
case looks like: **no red anywhere**, every branch working, and the count at the top is the only
thing on screen saying anything is wrong.

**Built and committed 2026-09-15** — `b13319ac`, `client/lib/screens/phone/call_flow_chart{,_pane}.dart`,
as a section appended to Settings (appended, not inserted: `_section` is an index, and inserting
in the middle broke thirteen tests in `phone_screen_test.dart` before the comment now in that
file existed to say so).

> **One defect, found by Phone reading the built code against their own contract — not by a
> test, and no test could have found it.** The caller's brand outline was keyed off
> `node.kind == entryId`, comparing a **kind** against an **id**: two namespaces that coincide on
> exactly one node because their renderer writes `$add('caller', 'caller', …)`, id first and kind
> second. Correct output today; a rename on either side removes the outline and **nothing goes
> red**, and a missing outline reads as a design choice rather than a fault. Fixed and pinned
> both directions in `faebf2df`.
>
> Phone also named a graph neither the gallery nor the first tests had: **the smallest real
> output is two nodes, not four** — `caller → arrival` for a number somebody imported and never
> pointed at EzBizi. Now covered.

**3 is drawn to be the version that cannot go wrong** — no pan, no zoom, no layout engine,
identical at 760px and 340px, and **the only option where a never-reached node has an obvious
home**: at the bottom, outside the tree, because that is exactly what it is. Its cost is the
brief; he asked for graphic and it is an indented list with good manners. **4 is drawn to be
rejected on purpose**: a canvas that needs panning to answer its own question is a canvas that
gets opened once.


---

## 21 · Work Hours: correcting and deleting a shift — BRIEF, not yet drawn

**Asked for by Jose, 2026-09-23**, in the same message as the never-ending-shift
bug: *"also we should have a correction system as well to be able to edit or
eliminate shifts"*.

**The API and the grant are built and merged** (Clock In, `52d210f3`). Nothing
on this page is waiting on the backend. What is missing is the affordance, and
it names a new element — a way into editing a row — so it belongs here rather
than being shipped as craft.

### What exists to draw against

`GET /api/v1/hr/time-clock/week` already returns, per shift row: `id`,
`clocked_in_at` / `clocked_out_at` (UTC instants), `clocked_in` / `clocked_out`
(wall clock, already formatted in the viewer's zone and 12h/24h preference),
`duration_seconds`, `open`, `ended_by`, `outside_schedule`, `extensions`.

- `PATCH /api/v1/hr/time-clock/shifts/{id}` — body may carry
  `clocked_in_at` and/or `clocked_out_at` as UTC instants. **An absent key means
  UNCHANGED.** Sending `clocked_out_at: null` on a finished shift is refused
  (422, `errors.clocked_out_at.0`); on an OPEN shift, omitting it leaves it
  open.
- `DELETE /api/v1/hr/time-clock/shifts/{id}`.
- Both answer `200` with **the whole recomputed week**, same shape as the GET,
  and honour `?on=`. So the pane can replace its state from the response rather
  than refetching — the total, days worked and average all move on a correction
  and none of them can be derived client-side.
- Refusals arrive as 422 with a sentence: *"Clock out must be after clock in."*,
  *"A shift cannot be in the future."*, *"These times overlap another shift for
  the same person."*, *"A finished shift cannot be reopened. Delete it
  instead."* **Render the server's sentence**; they are worded to be shown.
- `403` if the viewer lacks `hr.hours.correct`.

### The gate, which the drawing has to respect

`hr.hours.correct` is a **separate grant from the Work Hours tab**. Somebody may
hold `hr.hours.view` and not this. So Edit and Delete must be absent — not
present-and-refusing — for a viewer without it, on `me.permissions`. An
affordance whose gate is elsewhere is the failure this queue has hit twice.

### Three things the design has to answer

1. **Where the affordance lives on a row.** The rows are dense — time in, time
   out, chips, duration — and a trailing icon pair on every row is a lot of
   furniture for something used rarely. Hover-reveal is not available on touch.
2. **Deleting is destructive and has no undo in the product.** The audit keeps
   both times, but nothing in the UI reads it. A confirm step is expected;
   whether it is a dialog, an inline two-step, or an undo snackbar that holds
   the request is a design question.
3. **An open shift is the commonest correction** — the forgotten punch. Setting
   only an end, on a row that has no end yet, may deserve its own affordance
   rather than the full editor.

### One piece of copy the backend did not ship

`ended_by` can now be `correction`. The row chip renderer draws
`time_clock.auto` for `schedule` and nothing for an unknown value, so a
corrected shift currently shows no chip at all and does not break. **If the
design wants an "Edited" chip, the string is yours to add** (`ui_strings`, en +
es, plus the Dart fallback) — it was deliberately not minted here, because a key
with no call site is the thing this queue keeps finding unbuilt.

### Context worth having while drawing it

The correction system exists because a cap had to be invented. A shift that
nobody closes is now auto-closed at `time_clock.max_hours` (org setting, default
16), and **16 hours is a policy, not a measurement** — the five production rows
this shipped for were closed at 16h each when nobody knows when those people
actually went home. Correcting them is the first real use of this screen, and
Jose will be the one doing it.
