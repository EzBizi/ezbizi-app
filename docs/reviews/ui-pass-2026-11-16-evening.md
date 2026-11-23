# UI pass — 2026-11-16 evening (phone 375/320, light + dark)

**How.** Production was behind the after-hours lock screen, and leaving it
writes an audit row, so this pass ran the 0.0.0579 client (`3b67b2262`) against
a local copy of the app: a private schema (`ezbizi_uiux_review`), the
app-store demo tenant (`review:seed-organization`, plus the inventory, leads
and inspection demo seeders) and `flutter build web` served on 127.0.0.1. Every
module was opened from the drawer at 375 wide; the bottom bar was also checked
at 320, in dark.

## Bottom bar (Jose's "Communicati/on" screenshot)

Fixed in 0.0.0578 (`f1d070bc9`) and still fine: Jobs · Scheduling · Leads ·
Comms · More, one line each at 375 and 320, light and dark. "Scheduling" scales
down at 320 and does not wrap.

## Fixed on `session/uiux-pass4`

1. **Module header cut the title on a phone.** Customers read "Custo…", Fleet
   Management "Fleet Ma…", Automations "Auto…". The title now keeps its
   width. The action fits beside it, or takes its own line under a long title.
2. **Communication › Phone:** Dial / Team / Messages / Voicemail took two lines,
   with Voicemail alone on the second. They now fit on one line on a phone (no
   icons, tighter chips).
3. **HR › Dashboard:** Day…Year wrapped, with Year alone on the second line. It
   is now one line that scrolls sideways.
4. **Task Board on a phone:** the search was 110 px wide and read "Se…", and
   New task was pushed into the ⋯ menu. The search has its own full-width line,
   so the view picker, New task and ⋯ fit on the first line.
5. **Inspection › Generated Reports on a phone:** the list and the whole first
   report were stacked in one scroll. Now the list shows alone, a tap opens one
   report, and "All reports" goes back.
6. **Leads:** with a filter on, 8 leads showed "No enquiries yet". It now says
   "No leads match these filters". The empty-state text ran to the screen
   edges and now has margins.

## Seen, not fixed (owner or a design choice)

- **Jobs / Customers / CX Reviews on a phone:** 4 to 7 rows of controls sit
  above the first row, and the list starts below 70% of the screen. This needs a
  drawn option: one "Filters" row, as Leads did in design 171.
- **Customer record › Jobs:** an empty "—" amount column takes about 60 px, and
  the job titles are cut to "Spring snapped…".
- **Job panel:** the status shows twice (a chip and the Status field). Save is
  lit before any edit.
- **Fleet › Vehicles:** an orange "6" badge on each card has no label.
- **Task Board:** the default "Recurring Tasks" group is purple (`#A25DDC` in
  `TaskBoards.php`).
- **Gutters:** content sits at 12 px on several tabs (Financials › Invoices,
  Leads search) while the title sits at 16. That spans 113 files and needs its
  own pass.
- **Jobs › Upcoming:** a Completed job with no date is listed under "Not
  scheduled".
- **Email:** the empty inbox has no Settings button. Communication's empty state
  has one.
