# Email — review 2026-11-02 (prod, measured as jose@ezbizi.com, read-only)

## 1. Working
- Mailboxes list: 54 connected, 200 in 1.5s.
- All inboxes › Primary / People / Notifications: 200; People and Notifications 0.4s, Primary 3.1s.
- Filters: 2 shared A-1 Chimney folders (Google Local Services: 230 rows, Workiz: 135 rows); they file their mail out of the inbox correctly.
- Single inbox: folders, Inbox/Sent/Trash/Spam/Drafts, a message opened, People: all 200 in under 0.3s.
- Workspace / workspaces / new-mail alerts: 200 in under 1s.
- Fixes shipped since 10-28 are live: dark-mode reading pane, inbox refresh after connecting, Connected-page wording, reconnect no longer shows a false "Could not reach the server".

## 2. Broken
- **All inboxes (no tab) took 20.5s again.** Cause: the cache warmer (`ezbizi:warm-inbox-headers`, every minute, 50s budget) always started from the same inbox. It re-read the same ~24 busy inboxes each run and the rest went cold. Measured: 28 of 66 first pages live; one manual run re-read 24 of 67 in 52s. **Fixed: 68f791b21** — each run starts where the last one stopped. Regression test included. Waiting on release; I'll re-time after.
- **Filter folders take 19–20s** (Google Local Services, Workiz). Cause: a folder is a live Gmail search across every inbox, and searches aren't cached. Not fixed yet; see Suggestion 1.
- **acehomecaretx@gmail.com (Ace) shows "connected" but has no token**, so it shows "not connected yet" on every inbox load, and the warmer fails on it every minute (silently). The row's state is wrong since 10-12. The fix is to set it to not-connected, or reconnect it; see section 5.
- **5 failed mail jobs, 10-28 → 10-29: SMTP "451 4.4.2 Timeout waiting for data".** Known cause: a failed SMTP send keeps its broken connection until the worker restarts. None since 10-29.

## 3. Cohesiveness
- In web only, HTML emails render on white inside a dark app, because senders design them for white. Intentional, but it looks inconsistent; UI-UX to decide.
- Inbox errors use a long sentence per inbox ("…is not connected yet, so there is nothing to show — this is not an empty inbox…"). Other modules use one short status chip.

## 4. Suggestions (ranked)
1. Cache filter-folder searches the way first pages are cached (the warmer runs them too): folders go from 20s to ~0.3s.
2. Give the warmer a bigger budget or run 2 workers in parallel: it reads ~2s per inbox, so 67 inboxes need ~2 minutes per full pass. Payoff: no cold inboxes even when several change at once.
3. Have the warmer mark an inbox "needs reconnecting" when its token is missing or refused, instead of silently skipping it: wrong "connected" states show up the same day.
4. Restart the queue worker automatically after an SMTP 451: no repeat failed sends.

## 5. Needs Jose
- acehomecaretx@gmail.com (Ace Home Care TX): reconnect it, or should I mark it not-connected (taking a dump first)?
