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

## Registration — two calls at the start of every session

1. `get_session("self")`. If the title is not `EzBizi - <Role>` for the role
   you are holding, `set_session_title("self", "EzBizi - <Role>")`.
2. Find your role below. If the id is not yours, you are the new holder:
   replace the id and the date, stage **only this file**, commit. A row
   with a dead id is a dead letter; a row with a wrong id is worse.

   **Staging only this file is necessary and not sufficient.** Path-limiting
   stops you taking another *file*; it does not stop you taking another
   session's uncommitted *lines inside this one*, because `git commit --
   <path>` commits the WORKING TREE state of that path. On 2026-09-04 two
   sessions proved it within minutes: `9032be78` says "Inspection" and carries
   the Customers row too, and `eac81763` says "Customers" and actually moved
   the Notifications row. Nothing was lost — every row was right either way —
   but both messages are wrong about what they contain, in the one file whose
   whole purpose is being trustworthy about who is who.

   Build the blob from HEAD plus your own line, so nothing can ride along:

   ```bash
   BASE=$(git rev-parse HEAD)
   git show "$BASE":docs/SESSIONS.md > /tmp/row.md
   # edit ONLY your row in /tmp/row.md
   export GIT_INDEX_FILE=$(mktemp -u)
   git read-tree "$BASE"
   git update-index --cacheinfo 100644,"$(git hash-object -w /tmp/row.md)",docs/SESSIONS.md
   C=$(git commit-tree "$(git write-tree)" -p "$BASE" -F /tmp/msg)
   git update-ref refs/heads/main "$C" "$BASE"
   ```

   `GIT_INDEX_FILE` is per-SHELL and every agent tool call is a fresh shell, so
   stage and commit in ONE call or git silently uses the shared index instead.
   Check the result with `git show <sha> --stat` AFTER the fact — never with
   the stat you printed while staging, which is accurate about a tree your
   commit may not have used.

Sign anything that matters with your desktop id. A pipe name in a message
identifies nobody an hour later.

## EzBizi roles

Ids from `list_sessions`; titles and dates refreshed from it at 2026-09-04
16:24 UTC, after the restart below. Sessions not seen in two days stay
listed because their id is still the only way to reach them; the date says
how stale.

| Role | Desktop session id | Title today | Last seen | Pipe name (volatile) |
|---|---|---|---|---|
| **Deployment** (releases, `scripts/`, `client/VERSION`, stores) | `local_072bee23-4a25-4361-9335-c7a59fcfff11` | EzBizi - Deployment Lead | 2026-09-04 | `ezbizi-99` at 16:24 UTC, derived |
| Apple (App Store Connect, screenshots, review) | `local_ac2289f8-a1a6-4454-962e-21adf9706bca` | EzBizi - Apple Module | 2026-09-04 | — |
| Marketing module | `local_067b17d7-5a25-4047-8eef-d6d0da3565bc` | EzBizi - Marketing Module | 2026-09-04 | — |
| Web Management (WordPress plugin) | `local_89561020-da81-4ac1-b953-62324562ea00` | EzBizi - Web Module | 2026-09-04 | — |
| Suggestions module | `local_cd7e62ef-dcf0-4939-8467-452f94ce3d63` | EzBizi - Suggestion Module | 2026-09-04 | — |
| UI / UX | `local_f82e74ac-d808-40cd-bc95-ce473619183a` | EzBizi - UI-UX Manager | 2026-09-04 | — |
| Phone system | `local_d95f72da-cef3-4ffb-b8b1-8f4de859e7f3` | EzBizi - Phone System Module | 2026-09-04 | — |
| Google API | `local_8111908f-4bbb-400e-b842-6194f832387f` | EzBizi - Google API Module | 2026-09-04 | — |
| API integrations | `local_e76e374a-d27b-405a-a0f5-41853bed9bba` | EzBizi - API Integrations | 2026-09-04 | — |
| Amazon SES | `local_39c741ca-78e6-403b-a5e2-4a5d04cb7ed4` | EzBizi - Amazon SES Module | 2026-09-04 | — |
| Automations | `local_44b29c1e-d2ee-49db-9bde-6a428bd30354` | EzBizi - Automation Module | 2026-09-04 | — |
| Monitoring | `local_de24a58d-dac7-4a4b-9140-baba222174b2` | EzBizi - Monitoring Module | 2026-09-04 | — |
| Fleet | `local_7b76b5b7-2908-4091-86a2-b4a89c69e130` | EzBizi - Fleet Management Module | 2026-09-04 | — |
| Scheduling | `local_afb8a536-e66d-4fe3-b7a7-0126dba1cba4` | EzBizi - Scheduling Module | 2026-09-04 | — |
| Website (marketing site) | `local_b7fcc2e8-6683-4ce1-bea2-8853ba859ab0` | EzBizi - Website | 2026-09-04 | — |
| Email module | `local_8c185e1a-58d1-4882-8457-1ac0b338a35d` | EzBizi - Email Module | 2026-09-04 | — |
| Inspection | `local_1afc8662-0b8a-4b3c-b747-401b97b2a211` | EzBizi - Inspection Module | 2026-09-04 | — |
| Services | `local_2410a68f-5246-4715-82ac-faeb089a3a1b` | EzBizi - Services Module | 2026-09-04 | — |
| Inventory | `local_14fb86e6-7ca7-4051-96e7-d7ba4ec9a13b` | EzBizi - Inventory Module | 2026-09-04 | — |
| Android | `local_36f6540f-4f54-49b4-bb1d-8ee29cd1585d` | EzBizi - Android Module | 2026-09-04 | — |
| Customers | `local_90d427a5-c0b9-46e5-864a-efe9eb5b72da` | EzBizi - Customer Module | 2026-09-04 | — |
| Notifications | `local_7b312565-f2f8-44cf-ac5e-5426683f2cbf` | EzBizi - Notifications Module | 2026-09-04 | — |
| Training | `local_d30258ab-af2b-4b1b-9cf5-b0926fbe06ec` | EzBizi - Training Module | 2026-09-04 | — |
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
therefore blank until a session runs `/rename` in its new process, and
stays worth exactly one restart.

A1 Tools sessions live in `H:\A1Chimney\a1_tools` and are outside this file.
