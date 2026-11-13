# Agent rules for EzBizi

`StartUp.md` is the front door. STATUS wins disagreements. This file is the
short list that must not be forgotten between sessions.

## Work board — always

The board at `H:\Development\integrations-board` (http://localhost:8787) is the
living checklist for **everything** this product still owes: modules, screens,
console work, DNS, stores, mail, parked decisions — not only third-party APIs.

- **Start** a piece of work → create a track if none exists, with the steps you
  can already see.
- **Finish** a step → tick it on the board in the same turn as the work.
- **Waiting** on Mia, AWS, Apple, Google, Meta, D&B, etc. → set status to
  waiting and name what it is waiting on.
- **Park** something → say so on the card.

Do this before you claim the work is done. Do not put secrets on the board.

## Developer logins

Every **connection we own** (Google Cloud, Play Console, Apple Developer, Meta,
TikTok, LinkedIn, YouTube, X, Pinterest, Azure app registrations, and anything
similar) is created and administered as **`development@ezbizi.com`**, not
`jose@ezbizi.com` and not a personal Gmail. That mailbox exists (it administers
the EZBIZI LLC Google organization; see `docs/BUSINESS-PROFILE.md`). Tenant OAuth (a customer connecting *their* Page or inbox) is unchanged:
they still sign in as themselves.

The board does **not** replace `docs/EZBIZI-STATUS.md` or `docs/EZBIZI-TODO.md`.
Those are the repo of record. The board is so the next session (and Mia) can
see mid-flight work without rereading a chat.

## Standing

- A1 Tools may be read. Its code is never copied in.
- Never edit production on the server as shipping. Push your branch to `origin` and
  ask the deploy desk (`docs/SESSIONS.md`) to cut; it runs `scripts/release.sh`, which
  runs `scripts/deploy.sh` for web. Scripts and flags: `docs/DEPLOY.md`. The desk's own
  mistakes and the rules they produced: `docs/RetardMoments.md`.
- Never point a sandbox at the production database.
- Secrets stay off the repo and out of chat.
- **File on the current view, not on reach.** `/me.companies` and `/me.franchises`
  are every place this person may ever act. The top-bar switcher narrows that to
  `companiesInView` / `franchisesInView` / `filingCompanyId`. A picker, Connect
  card, Add pane, or default `company_id` that iterates `me.companies` lets
  somebody standing in A-1 Chimney file on Everdoors. A company view also
  cannot modify the organization (authority does not flow up). The switcher is
  the one control that lists reach. Every new module copies Inventory/Fleet/Phone,
  not a raw `me.companies` loop. Test a company view with a sibling in `/me`.
