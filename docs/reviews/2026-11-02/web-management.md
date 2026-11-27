# Web Management review — 2026-11-02 (measured on prod)

## 1. Working
- 69 connected sites report to EzBizi; 68 on plugin 1.26.24 (rolled out today), all reporting within the last hour.
- Booking pages return 200 with the widget: www.a-1chimney.com, sac.a-1chimney.com, chimneystar.com. 59 sites report `has_booking=1`.
- Booking short codes: 236 issued.
- Chat is on for the 17 sites restored 10-29 (home pages 200 with the widget: everdoors, premiergaragerepair, a-1garagedoorrepair).
- Failed jobs for Web, SEO or Booking in the last 7 days: 0.
- GBP hours: review-only path passed 10-29; `GBP_HOURS_WRITE=true` on prod.

## 2. Broken
- **angisweep.com served 10-23's values until today.** Its cached-values transient had lost its expiry row, so WordPress treated it as never expiring and no fetch ran.
  - Fix: plugin 1.26.24 also checks the payload's own `fetched_at` stamp. Commit e3a4c2539, rolled out to every site. angisweep.com reported at 15:00.
- **sac.a-1chimney.com hadn't reported since 11-01 08:07.** Cause: low traffic. The plugin only refreshes when a page is viewed. It reported at 15:05 after a visit; not a defect.
- **`/booking/` returns 404** on a-1chimneysweep.com, speedychimneysweep.com and ct/ma/pa.speedygaragedoors.com. All report `has_booking=0`, so this is consistent: those sites have no booking page.
- ezbizi.com has never reported (`last_seen_at` null). Our own marketing site has no token.

## 3. Cohesiveness
- No new screen issues found. The Website panel's labels follow Leads' panel. UI-UX owns any fix list.

## 4. Suggestions
1. Have a scheduled server ping refresh quiet sites, so a low-traffic site never goes stale. Payoff: no stale phone numbers or hours.
2. Show "last reported" with a warning past 24h in the Website panel. Payoff: a stale site is visible without a query.
3. Create booking pages for the 5 sites above, or hide booking links for them. Payoff: no dead-end links.

## 5. Needs Jose
- GBP hours: which location and hours should the first live write use? Or should it be a change-and-revert test?
- Should ezbizi.com be connected to EzBizi like the client sites?
- Do the 5 sites without a booking page (a-1chimneysweep, speedychimneysweep, speedygaragedoors ct/ma/pa) get one?
