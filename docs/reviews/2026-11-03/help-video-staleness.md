# Help videos: which are stale (2026-11-03)

Jose, 11-03: "i want to make sure that the walkthroughs and videos are up to date since we have made a lot of changes since last time, so bring them up to speed."

**Method:**
- "Recorded" is the date of the newest mp4 in `H:\Development\EzBizi-videos\<module>`. `GET help/videos` carries no dates.
- "Changes" counts the non-merge commits on main touching that module's client screens since that date. The SHAs listed are the newest few.
- A module counts as stale when a screen, tab or flow shown in its chapters changed. A style-only sweep (one search style, no purple, wording) is not enough.

## 1. Module videos

| Module | Recorded | Changes | What changed (newest SHAs) | Stale |
|---|---|---|---|---|
| a1leads_gbp | 10-14 | 17 | GBP Listings WhatsApp column 8a3077b90; Posts to approve fa4bcb4a2 | **yes** |
| account | 10-16 | 0 | none | no |
| ai_assistance | 10-14 | 9 | WhatsApp groups chip wall → picker 3696d8f4f; Manuals 4 tiles 8e22724cb | **yes** |
| automations | 10-14 | 11 | tag conditions 4ece166b6; on/off Undo 7f2792694 | **yes** |
| customer_experience | 10-14 | 20 | Posts to approve fa4bcb4a2; Reviews "Google ✓" chip 7f2792694 | **yes** |
| customers | 10-14 | 11 | customer tags 68a846ac3 | **yes** |
| dashboard | 10-14 | 4 | last tile fills the row d03914ad0; notes collapse 2e820dc01 | no (minor) |
| development | 10-16 | 4 | whole tab redesigned (design 168) 1f8164be3 | **yes** |
| email | 10-14 | 19 | All tab 44560cfec; full-pane reader below 900 px 19a1c6daf | **yes** |
| field | 10-14 | 11 | Recordings summary and transcript ebaafeb62; disclosure 53c2148e4 | **yes** |
| financials | 10-14 | 27 | Lead source results tab c9b4fad2c | **yes** |
| fleet_management | 10-14 | 8 | Vehicles open on all states in Company · Branch sections 21605c56e; single date field 3b57f5c63 | **yes** |
| guidelines | 10-14 | 1 | search style only 87394a09c | no |
| hr | 10-14 | 29 | Records no-start-date/no-contract chips 11e39868d; Payroll chip labels 8e22724cb; single date field | **yes** |
| inspection | 10-14 | 6 | Testing tab c365772bc; step bar b03f5ed08 | **yes** |
| inventory | 10-14 | 8 | style only (the demo data shown was removed 11-02) | no, but re-shoot if its demo rows appear |
| jobs | 10-14 | 36 | Estimate Board 5f832e2d8; Record pill 636c3e00b; single date field; before/after photo labels 164860fe9 | **yes** |
| leads | 10-14 | 33 | phone layout (design 171) cedb56d72; coloured transcripts 8d9b41c9a | **yes** |
| marketing | 10-14 | 17 | Newsletter + New list fe719eca3; GBP draft labels a29c53b72 | **yes** |
| monitoring | 10-14 | 14 | style and cleanup only | no |
| phone | 10-14 | 12 | Team/Alerts search 7f2792694; compact voicemail cards d03914ad0 | **yes** |
| reports | 10-14 | 28 | People Phone/Sales (design 170) aff85d59a; Client/Booking override cf1e98fab, 69d6e8f95 | **yes** |
| sales (PM-2) | 10-14 | 12 | Submitted keeps its jobs 3f560bde7; wording 7069476a7 | **yes** |
| scheduling | 10-13 | 35 | Availability picker, not chips 83c8a3145; day tiles show spots 7f2792694; wording ("Calendar view") | **yes** |
| services | 10-14 | 4 | Delete restyled, search style 7f2792694 | no |
| settings | 10-16 | 38 | Integrations token health f67bc508a; many settings-screen edits | **yes** |
| suggestions | 10-16 | 4 | paste a picture d209a835f | no (minor) |
| tasks | 10-14 | 13 | Calendar and Timeline views, Timeline and Formula columns ec3ce7ec6 | **yes** |
| training | 10-14 | 7 | style only | no |
| work_hours | 10-14 | 2 | PTO Requests (design 146) 20149c04f; Daily approval tab (design 136) 694c69caf | **yes** |

**Stale: 22 of 30.** Not stale: account, dashboard, guidelines, inventory, monitoring, services, suggestions, training.

## 2. New screens with no video

| Screen | Design | SHA | Plan |
|---|---|---|---|
| Company (Ownership, Processes, KPIs) | 175 | d1cf0987c | new video `company` |
| Estimate Board | 172 | 5f832e2d8 | chapters in the Jobs re-shoot |
| Posts to approve | 176 | fa4bcb4a2 | chapters in the Customer Experience re-shoot |
| Task Board Calendar and Timeline | 178 | ec3ce7ec6 | chapters in the Tasks re-shoot |
| Email All tab and phone reader | — | 44560cfec, 19a1c6daf | chapters in the Email re-shoot |

**Recording rules:**
- under 2 minutes, 16:9 ([[review-videos-under-two-minutes-16x9]]);
- demo or Jose's own records only;
- after 0.0.0526 is live.

Scheduling (150 s) and Monitoring (144 s) are over 2 minutes today; cut them down when re-shot.
