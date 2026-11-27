# StartUp

EzBizi is a multi-tenant field-service CRM: Laravel/PHP/MySQL backend, Flutter clients, and a marketing website. A1 Tools may be read for business context; its code is never copied into this clean rewrite.

Read these in order:

1. [STATUS](docs/EZBIZI-STATUS.md) — current state; wins disagreements. Moving counts stay in its generated block.
2. [Active constraints](docs/ACTIVE-CONSTRAINTS.md) — current restrictions and clearly marked historical review constraints. Do not raise minimum client versions.
3. [Ownership contract](docs/OWNERSHIP.md) and [build plan](docs/EZBIZI-BUILD-PLAN.md) §2.1, §3, §4 and §6 — tenant identity, current view, ownership, access and configuration.
4. [Development rules](docs/DEVELOPMENT-RULES.md) — isolation, shared commits, verification and product conventions.
5. [Session directory](docs/SESSIONS.md) before messaging any other session (ids, not pipe names).
6. [Deploy](docs/DEPLOY.md), [TODO](docs/EZBIZI-TODO.md), [module owners](docs/MODULE-OWNERS.md) and [agent inbox](docs/agents/inbox/) for the work being undertaken.

The [documentation index](docs/README.md) links setup, feature specifications, release notes and history. The former long StartUp is preserved in [history](docs/history/2026-09-08-startup.md); its incident narratives are evidence, not current commands.

Track every piece of work on the [living board](http://localhost:8787) at `H:\Development\integrations-board`. Start a track, tick completed steps in the same turn, and name waiting dependencies or parked decisions. The board supplements STATUS/TODO; it does not replace them.

Standing rules: never ship by editing the production server; push to `origin` and ask the deploy desk to cut (release scripts, [Deploy](docs/DEPLOY.md)). Never point tests or sandboxes at production. Keep secrets outside repo, board and chat. Own developer connections use `development@ezbizi.com` (the mailbox exists); customer OAuth uses their own identity. Do not overwrite another task's files or discard unrelated working changes.

File on the current view, not all reachable places. The switcher alone lists reach; other pickers use `companiesInView`, `franchisesInView` and `filingCompanyId`. Inherited reads never confer upward writes. Verify a company view with a sibling in `/me` before claiming a module correct.

Finish the authorized task, verify the actual result, update its records, and report what is complete versus still blocked. Deployment remains a separate measured outcome from passing local tests.
