# UI/UX deep dive, 2026-11-02 (evening)

Jose: "go into every module, click every tab, and sub tab". This covers app.ezbizi.com v0.0.0517 as Triplex Group, desktop 1568 wide, dark theme, read only. Nothing that writes or sends was clicked. Two exceptions:
- one availability look-up (zip 19103);
- opening Leads, which auto-selects the newest lead.

Emails, PM-1 › Settings, Financials' inner tabs, and the light and phone layouts were not opened. The first review is ui-ux.md in this folder; this adds to it.

## 1. Fix first (broken, or against a standing rule)

| # | What | Where |
|---|---|---|
| 1 | **Dead sub-tabs.** Once the Service areas map loads, the Technicians, Locations and Routing & suggestions sub-tabs don't respond to clicks. Every area in the list also reads "All areas · Main · 0 ZIPs". | Scheduling › Settings › Service areas |
| 2 | **"Coming soon" on live modules.** The tiles for PM-1, PM-2, Guidelines, Financials and Task Board all say "Coming soon". | Dashboard |
| 3 | **Raw API errors.** Failed posts show provider error text with URLs ("Apps with Trial access may not create Pins…", "unaudited_client_can_only_post_to_private_accounts"). Replace with one plain line and a Details link. | Marketing › Social › Dashboard |
| 4 | **Raw erased identity.** A removed person shows as "removed-01a17f2e-…@invalid". Show "Removed person". | HR › Invitations |
| 5 | **More purple** (beyond Tone.review, item #1 of the first review). The "Stopped too long" dots and legend, and the "Recurring Tasks" and "Guidelines" group headers. | Field › Map, Task Board |
| 6 | **Colour means the opposite.** In the Jobs donut, Canceled is green and Done is pink. | Dashboard |
| 7 | **Red for normal states:** "Waiting on: the employee" on every row, clock-out times, Do not disturb, "Needs a pass 0", "Didn't clock in". Red should mean a problem; these should be caution or muted. | HR Onboarding, Work Hours, Communication › Team, HR record › Training |
| 8 | **Chip row vanishes.** When a filter returns 0 results the branch chip row disappears, the layout jumps, and the next click lands on a different chip. The list also opens on "Connecticut 6" instead of All. | Fleet › Vehicles |
| 9 | **Dead affordance.** Day tiles look selectable but tapping one does nothing. | Scheduling › Availability › Calendar |
| 10 | **One stray click turns off a live automation.** Every card has a live toggle with no confirm and no undo. Add an Undo snackbar. | Automations |
| 11 | **Destructive actions styled as primary.** "Disconnect" is an accent-blue link on every account, and "Delete service" sits right under Save. | Marketing › Accounts, Services |
| 12 | **Lists without search** (the every-list rule): Communication › Team, Communication › Alerts, Field alerts (first review #11). | |

## 2. Patterns across modules (one fix each, many screens)

- **Chip walls → a SearchPicker for "Company · Branch":**
  - Scheduling Area/Calendar: 30 branch chips.
  - AI › WhatsApp groups: about 60 chips in 8 rows.
  - Communication › Reports: 13 line chips.
  - Fleet: 19 chips that wrap 4 rows in split view.
- **Third-level tabs are drawn two ways.** Pills in the Job and Customer panes and in PM-2; underlined text in HR records and Memberships. Web Management goes **four** levels deep (Marketing › SEO|Widgets › General…SEO Helper pills › site list); flatten it.
- **Unused width:**
  - Scheduling › Availability (a 480 px column; slot chips truncate to "13 Spots L…").
  - Overbooking (560 px).
  - Review links (left 40 %).
  - Job and Customer panes (40 % used).
  - The Schedule board (a 250 px gap before Unassigned).
  - Monitoring › General (first review #12).
  - Work Hours › General and Fleet › General (60 % empty below).
- **Empty until you choose, though there is an obvious default.** Open the first item instead:
  - Inspection › Generated Reports: show the recent ones.
  - Inspection › New: show today's inspection jobs.
  - Scheduling › Routing: opens on an area with no jobs.
  - Field › Route: opens on the signed-in person, who has no jobs.
  - Reports › Workspace and Google Ads; Monitoring › Files and Map; Marketing › Widgets.
- **Dates typed into 3–5 boxes** (Month/Day/Year/Hour/Minute/AM): Jobs, Estimates, HR date of birth, Fleet registration and inspection. Use one date (or date-time) field with a picker.
- **Long spinners with no skeleton:** Email › Settings (about 8 s), Reports › Profit & Loss (over 15 s), Web Management › Pages.
- **Save/Cancel footer on read-only tabs:** Job › Money/Photos/Notes and Customer › Money.
- **Sidebar:** taller than the window, with the lower group cut mid-row and no scroll hint. While a SidePanel is open, the first sidebar click only closes the panel.

## 3. Wording and labels

- "franchises" and "Franchise" in place of the org's branch label: the Jobs header and the Customers picker (first review #8). PM-2 stacks "Ohio / A-1 Chimney", branch above company. HR's "Where people work" uses a hyphen ("A-1 Chimney - Connecticut").
- **Text still says Workiz is in charge:** "Changes go straight to Workiz" (Schedule › Unassigned) and "From Workiz · read only" (Jobs money).
- **Title Case:**
  - "Search by Zip Code", "Calendar View" and "Spots Left" (Availability);
  - "Ready for Submission" (PM-2);
  - "AI Rescheduling Suggestion" (singular);
  - Task Board groups ("Open Tasks").
- The Dashboard legend uses "done pending approval" while the rest of the app says "Done, pending approval".
- Availability shows "NEW JERSEY · NEW JERSEY" for 19103. It should read "A-1 Chimney · New Jersey".
- HR › Time off: "2 days a quarter … resets 9/8/2027" contradicts itself. Access says "Unlike Deactivate" with no Deactivate button on that tab.
- The Customer › Money tab shows "$50.71 invoiced" next to "Paid $50.72".
- CX Reviews: the "Board" view is a feed, not a board. GBP Performance lists "A-1 Chimney" on all 37 rows, with no city to tell them apart.
- Customer names carry leading punctuation (". Lisa Abbott", "? Chris Crowell") and sort under "#". Leads shows "Unnamed enquiry" when the phone or email could be shown.
- Training › Settings repeats a big "Settings" title inside its own tab.
- Every PM-1 tab repeats a 2-line intro. PM-1 Overview tiles list about 10 branch names in 9 px text. PM-1 Rules prints about 40 branch names in one paragraph; show "40 branches ▸" instead.

## 4. Smaller, per module

- **Dashboard:**
  - the tile grid leaves holes (3 of 4, a lone Marketing tile, a half-width Leads tile);
  - delta chips are grey in both directions;
  - the Phone tile has no big number;
  - the Day chart for Customers is a spike followed by a flat line.
- **HR › Payroll:** two chip groups both mention W-2 ("W2 signed/not signed" and "All types/W-2/1099"). Label them, or merge into a Type group and a W-2 form group.
- **Work Hours:**
  - PTO rows wrap to 3 lines for some people;
  - an amber "From 11/8/2026" pill appears for a normal future date;
  - the Requests search and Status picker are each 45 % of the width.
- **Scheduling:** Locations has two stacked search fields. "Locations" is both a top tab and a Settings sub-tab. Helpers shows an empty month grid with no empty-state line.
- **Estimates:** the # column shows truncated raw ids, the Job column is mostly "—", there are no Where/Type filters, and a line reads "(No description in A1)".
- **Communication:**
  - Messages shows our auto-reply as every row's last message; show the customer's last message;
  - Voicemail cards fit 7 per screen;
  - "Dismissed by all 0" is green for alerts sent to nobody;
  - some "Bad review" alerts are positive reviews (classifier; for the CX owner).
- **Services:** 36 of 37 services are under "Other", so the grouping adds nothing.
- **Email:** Workspace domains are listed in both Settings and the Google Workspace tab.
- **Marketing:**
  - Calendar stacks an empty Week view and an empty Month view;
  - shortcode chips sit beside every Web Management field;
  - the Widgets site list is drawn differently from the SEO site list.
- **AI:** "Welcome message" is cut off in a 200 px input. Manuals uses 2 tiles where the other tabs use 4.
- **Monitoring:** everyone shows green "Online", off shift too. Offline camera tiles are plain black.
- **Task Board:** empty groups each draw a full table header.
- **Inventory:** demo seed data ("Demo warehouse", "Sample box") is on a live org; keep or remove (Jose). Its pattern (tiles, table, search on every tab) is the one to copy.

## 5. Suggested order

1. Section 1, items 1–12, as one batch. Items 1, 2, 4, 5, 6, 8, 9 and 11 need no vote.
2. Add §2 chip walls and §2 dates to the "one filter bar" gallery (#3/#4/#6/#7 from the first review). These are the same decision.
3. Add the §2 tab levels to the design-103 follow-up, as a small gallery.
4. §3 wording, as one sweep.
