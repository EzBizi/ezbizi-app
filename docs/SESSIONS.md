# Session directory

Who is holding which role on this machine, with the one identifier that
cannot point at the wrong session. `docs/MODULE-OWNERS.md` says what each
role owns (by path and SHA); this file says how to reach it.

Measured 2026-09-04 by the deploy desk, from `~/.claude/sessions/*.json`,
`ccd_session_mgmt list_sessions`, `ListAgents`, and the Claude Code docs.

## Three names, one of them durable

| | Where it shows | Set by | Lifetime |
|---|---|---|---|
| **Desktop session id** `local_<uuid>` | `get_session("self")`, `list_sessions` | nobody | **Permanent.** Never reassigned. A message to it reaches that session or nobody. |
| **Desktop title** `EzBizi - <Role>` | the sidebar; the `From:` line of `ccd_session_mgmt send_message` | Jose in the sidebar, or the session with `set_session_title("self", …)` | Survives restarts and resumes. **Not unique** — three sessions are titled "Ezbizi website daily catalog" today. |
| **Pipe name** `ezbizi-69`, `a1-tools-b1` | `ListAgents`; the address `SendMessage` takes | derived from the folder plus two hex digits | **Dies with the process.** Lives in `~/.claude/sessions/<pid>.json` as `name`, `nameSource: derived`. A restart is a new pid and a new name. |

**`/rename` works in a desktop window — measured 2026-09-04 16:03 UTC.** The
Suggestions session typed `/rename EzBizi - Suggestions module`; its record
went from `name: ezbizi-7e, nameSource: derived` to that string with
`nameSource: user`, `ListAgents` showed the new name to every peer within
the minute, and its `[066252]` ref did not change. Spaces are fine. It pins
the name for **that process only** — do it again after every restart — and
changes neither the desktop session id nor the title. `claude --name <name>`
does the same at launch for CLI sessions.

So the convention: **`/rename` to exactly your desktop title**, so the name
`ListAgents` shows, the `From:` line of `send_message`, and this table all
say the same words. Until a session has done that, its pipe name is noise.

The record that names a pipe holds the CLI transcript id, not the desktop
id, so nothing on disk maps `ezbizi-69` to `local_072bee23…`. Only the
session itself can state both, which is what the registration below is for.

## The rule

**Address a role by its desktop session id.** Look it up in the table, send
with `ccd_session_mgmt send_message(session_id, …)`. If that errors, the
role is unstaffed right now — write the message as a file in
`docs/agents/inbox/` (protocol in `docs/agents/README.md`), which survives
the session that was not there to read it. **Never guess a pipe name**, and
never take one from a document: `ezbizi-a1`, `ezbizi-5b` and the desktop id
`local_a7c13d4e…` were all tried for the deploy desk on 2026-09-04 and all
three were dead. The live one was in this table's first row the whole time.

## Registration — one writer, and it is the deploy desk

**Only the deploy desk edits this file.** It refreshes ids, titles and
dates from `list_sessions` and today's pipe names from what sessions report.
Every other session does two things at start and nothing else here:

1. `get_session("self")` and read your row. Title, id, date — all of it is
   the desk's reading of the desktop, and the **title is Jose's**: he sets
   it in the sidebar. Never call `set_session_title` on yourself. An earlier
   version of this file asked every session to, minutes before Jose renamed
   every window himself; no session had acted on it (checked with the one
   that was suspected), but any that had would have undone his rename and
   the desk would have mirrored the wrong string here. Withdrawn for that.
2. If your row is wrong or missing, `send_message` the Deployment id below
   with the correction. Do not edit the table. Two sessions tried "stage only
   this file" on 2026-09-04 and each commit still carried the *other's*
   uncommitted row, so two commit messages in this file's history describe
   changes they do not contain (9032be78, eac81763). Path-limiting keeps out
   other files, not other sessions' lines in this one. And the stakes are
   asymmetric: a row with a dead id is a dead letter, and somebody notices;
   a row with a wrong id delivers to the wrong session, and nobody does.

Sign anything that matters with your desktop id. A pipe name in a message
identifies nobody an hour later — and, measured below, not even now.

## EzBizi roles

Ids from `list_sessions`; titles and dates refreshed from it at 2026-09-04
16:24 UTC, after the restart below. Sessions not seen in two days stay
listed because their id is still the only way to reach them; the date says
how stale.

| Role | Desktop session id | Title (Jose's, from the sidebar) | Last seen | Pipe name at 17:55 UTC 2026-09-04 — recognition only, never an address |
|---|---|---|---|---|
| **Deployment** (releases, `scripts/`, `client/VERSION`, stores) | `local_072bee23-4a25-4361-9335-c7a59fcfff11` | EzBizi - Deployment Lead | 2026-09-04 | `ezbizi-99 [57ae91]` |
| Apple (App Store Connect, screenshots, review) | `local_ac2289f8-a1a6-4454-962e-21adf9706bca` | EzBizi - Apple Module | 2026-09-04 | `ezbizi-e0 [c500d7]` |
| Marketing module | `local_067b17d7-5a25-4047-8eef-d6d0da3565bc` | EzBizi - Marketing Module | 2026-09-04 | `ezbizi-76 [7f2040]` |
| Web Management (WordPress plugin) | `local_89561020-da81-4ac1-b953-62324562ea00` | EzBizi - Web Module | 2026-09-04 | — (no reply yet) |
| Suggestions module | `local_cd7e62ef-dcf0-4939-8467-452f94ce3d63` | EzBizi - Suggestion Module | 2026-09-04 | `ezbizi-83 [67ea80]` |
| UI / UX | `local_f82e74ac-d808-40cd-bc95-ce473619183a` | EzBizi - UI-UX Manager | 2026-09-04 | `ezbizi-e3 [a6b36a]` |
| Phone system | `local_d95f72da-cef3-4ffb-b8b1-8f4de859e7f3` | EzBizi - Phone System Module | 2026-09-04 | `ezbizi-7e [74574e]` |
| Google API | `local_8111908f-4bbb-400e-b842-6194f832387f` | EzBizi - Google API Module | 2026-09-04 | `ezbizi-02 [dd7f64]` — **same name as Inventory this minute** |
| API integrations | `local_e76e374a-d27b-405a-a0f5-41853bed9bba` | EzBizi - API Integrations | 2026-09-04 | `ezbizi-60 [a0a79f]` |
| Amazon SES | `local_39c741ca-78e6-403b-a5e2-4a5d04cb7ed4` | EzBizi - Amazon SES Module | 2026-09-04 | `ezbizi-d2 [59dcdf]` |
| Automations | `local_44b29c1e-d2ee-49db-9bde-6a428bd30354` | EzBizi - Automation Module | 2026-09-04 | `ezbizi-a1 [3b8cb3]` — **the old deploy desk's name; a stale note reaches Automations now** |
| Monitoring | `local_de24a58d-dac7-4a4b-9140-baba222174b2` | EzBizi - Monitoring Module | 2026-09-04 | `ezbizi-69 [d20c3b]` — was Deployment's name this morning |
| Fleet | `local_7b76b5b7-2908-4091-86a2-b4a89c69e130` | EzBizi - Fleet Management Module | 2026-09-04 | `ezbizi-85 [ff0aad]` |
| Scheduling | `local_afb8a536-e66d-4fe3-b7a7-0126dba1cba4` | EzBizi - Scheduling Module | 2026-09-04 | `ezbizi-a6 [d7a198]` |
| Website (marketing site) | `local_b7fcc2e8-6683-4ce1-bea2-8853ba859ab0` | EzBizi - Website | 2026-09-04 | `ezbizi-42 [e8b441]` |
| Email module | `local_8c185e1a-58d1-4882-8457-1ac0b338a35d` | EzBizi - Email Module | 2026-09-04 | `ezbizi-25 [e01003]` |
| Inspection | `local_1afc8662-0b8a-4b3c-b747-401b97b2a211` | EzBizi - Inspection Module | 2026-09-04 | `ezbizi-b8 [b0b326]` |
| Services | `local_2410a68f-5246-4715-82ac-faeb089a3a1b` | EzBizi - Services Module | 2026-09-04 | `ezbizi-d6 [d828ae]` |
| Inventory | `local_14fb86e6-7ca7-4051-96e7-d7ba4ec9a13b` | EzBizi - Inventory Module | 2026-09-04 | `ezbizi-02 [f15798]` — **same name as Google API this minute** |
| Android | `local_36f6540f-4f54-49b4-bb1d-8ee29cd1585d` | EzBizi - Android Module | 2026-09-04 | `ezbizi-f6 [b4987d]` |
| Customers | `local_90d427a5-c0b9-46e5-864a-efe9eb5b72da` | EzBizi - Customer Module | 2026-09-04 | `ezbizi-c2 [9c1c83]` — its fifth name today |
| Notifications | `local_7b312565-f2f8-44cf-ac5e-5426683f2cbf` | EzBizi - Notifications Module | 2026-09-04 | `ezbizi-b2 [708399]` |
| Training | `local_d30258ab-af2b-4b1b-9cf5-b0926fbe06ec` | EzBizi - Training Module | 2026-09-04 | `ezbizi-0f [d43683]` |
| AWS migration (cwd `H:\Development\AWS`) | `local_079b6a6c-b0c8-49f9-8568-152a1dd2a6b1` | MIGRATION | 2026-09-04 | `aws-68` at 15:57 UTC (inferred from cwd, not confirmed) |

**Website daily catalog** is not a role: a new session is started for it each
day, so it has had three ids (`local_cc91ca9e…`, `local_4a12ba2c…`,
`local_e5b5855a…`). **Scheduled-task runs cannot receive `send_message` at
all** — measured 2026-09-04, all three refused as "unattended". The only
channel to one is what it reads at start: `CLAUDE.md`, then this file. It
finds the deploy desk here; nobody needs to find it.

The Tasks-removal worktree session (`local_57b53ebf…`) finished on
2026-09-03 and its id returned "not found" on 2026-09-04: gone, not idle.

**Every role row above was told on 2026-09-04 by `send_message` to its id**:
21 delivered, the Marketing one queued behind its running turn. A row whose
session then updates its title or id is confirming it; a row that never
changes after a restart is stale, and the next person to reach it by id
will learn that from the error, not from this file.

**Power surge, 2026-09-04 about 16:15 UTC.** Every process on the machine
restarted and every session was cut off mid-turn. Measured at 16:24 UTC:
all 13 live pipe-name records are `nameSource: derived` again, none
user-set — a restart discards `/rename` by construction. Jose's renames
after the restart landed on the **desktop titles** (thirteen changed;
the column above is refreshed from `list_sessions`), which is the half
that persists. Ids are unchanged, as designed. The pipe-name column is
therefore filled from what each session reported at about 17:55 UTC and
is worth exactly one restart.

**Two things the replies measured that make a pipe name worse than
volatile.** It is not unique even at the same instant: `ezbizi-02` was two
live sessions at once, Inventory `[f15798]` and Google API `[dd7f64]`, in
the same `ListAgents` output. And it is recycled within the hour: `ezbizi-a1`,
the name the deploy desk answered to yesterday and three sessions spent a
day sending handoffs to, is Automations now; `ezbizi-69`, this desk's name
this morning, is Monitoring; Customers has had five names today. A dead
name bounces; a recycled one delivers to a stranger and looks fine. So the
column carries the `[ref]`, is dated, and is never something to send to.

**Not every actor on this repo is a Claude session.** On 2026-09-08 Jose
had **GPT Astra** (an OpenAI-based agent he drives directly) cut
`v0.0.0152` (`9960b3e1`, 94 files: automations engine, policies,
authentication, newsletter delivery, phone webhooks, and the release
pipeline — `deploy.sh`, `release.sh`, `deploy/post-receive`, a new
`deploy/receive-release`). Its commits carry Jose's git identity like every
other, it has no desktop id and no pipe name, `send_message` cannot reach
it, and no transcript search will find its work. If a commit appears with
no session behind it, ask Jose before hunting. The deploy desk did not cut
0.0.0152 and does not own that release; its pre-bumped VERSION means the
next `release.sh` run must be `--no-bump`, and its first real push installs
its new hooks on the box by design (the box held the old hook and no
`receive-release` at 13:20 UTC).

A1 Tools sessions live in `H:\A1Chimney\a1_tools` and are outside this file.
