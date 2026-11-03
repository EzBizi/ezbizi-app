# Workiz integration — review 2026-11-02 (measured on prod, ~09:55 ET)

## 1. Working
- API connections: 11 `workiz` connections, all `connected` (10 branches rotated 10-26, plus Everdoors Connecticut).
- Job sync: every branch synced within the last 3 min (14:53–14:56 UTC); the newest bookings are from this morning on 9 of 10 branches.
- New York's newest booking is from 10-27, but that is real: only 2 NY EzBizi jobs since then (JOB-54870 is mirrored; JOB-55198 was deleted 10-30 and audited `workiz.job_gone`).
- Projection: 2 unprojected rows in total (Illinois 1, Texas 1), out of roughly 15.7k rows synced in 24 h.
- Records, all refreshed today:

  | Record type | Rows |
  |---|---|
  | invoice | 22,771 |
  | payment | 36,047 |
  | estimate | 41,782 |
  | timesheet | 162 |
  | custom_field | 47 |
  | item | 1,145 |
  | job_type | 153 |
  | ad_group | 242 |
  | tag | 452 |
  | sub_status | 118 |
  | service_area | 110 |
  | external_company | 195 |

- Web-session readers, all landed today:

  | Reader | Rows | Last landed (UTC) |
  |---|---|---|
  | web_payment | 1,022 | 14:31 |
  | web_sale | 948 | 14:41 |
  | lists | — | 14:12 |

- Web login: connected (14:50).
- Outbound mirror: 0 `workiz.mirror_failed` events in 7 days; no Workiz entries in `failed_jobs` in 7 days.
- Phones: 10-29 and 10-30 bookings all reached Workiz with a phone (296 jobs). On 10-29, 4 phones differ between EzBizi and Workiz; those numbers were mistyped at booking, not a mirror fault (sent to the desk 10-30).
- Audit trail for 7 days: job_gone 25, moved_to_v2 10, v1_kept 10.

## 2. Broken
- Nothing measured broken in this module today.
- Shared-tree hazard, not a code defect: on 10-30 the shared checkout held uncommitted edits that would revert the Workiz writer and projection on main. The desk has flagged them as never to be committed.

## 3. Cohesiveness
- Workiz has no screen of its own; it shows up through Settings → Integrations and the jobs' Workiz extras. Nothing to list here beyond UI-UX's existing list.

## 4. Suggestions (ranked)
1. A per-branch "last synced / last booking / unprojected" health line on the Integrations card. Payoff: a stalled branch is visible without a prod query.
2. Retire the v1 read path once v2 covers the reports (v1 kept on 10-26 only for reports). Payoff: one key per branch to rotate.
3. Phone mismatch check: flag an EzBizi job whose customer phone differs from the Workiz client's. Payoff: catches mistyped phones like the 4 found on 10-29. Note that Workiz v2 cannot update a client, so the fix stays manual in Workiz.

## 5. Needs Jose
- Nothing. The second "Connecticut" is **Everdoors** Connecticut (company Everdoors), not a duplicate of A-1 Chimney Connecticut. Its Workiz key is separate and was not part of A-1's 10-26 rotation; it works.
