# Documentation index

Read [StartUp](../StartUp.md) first. [STATUS](EZBIZI-STATUS.md) wins disagreements about current state. Historical notes explain incidents; they do not establish today's behavior.

| Need | Document |
|---|---|
| Current state and measured production numbers | [STATUS](EZBIZI-STATUS.md) |
| Remaining obligations and owners | [TODO](EZBIZI-TODO.md), [live board](http://localhost:8787) |
| Reaching another session (ids, never pipe names) | [Session directory](SESSIONS.md) |
| What a piece of work looks like from start to deployed | [How I work](HOW-I-WORK.md) |
| Deploy-desk mistakes and the rule each produced | [RetardMoments](RetardMoments.md) |
| Operational restrictions | [Active constraints](ACTIVE-CONSTRAINTS.md) |
| Architecture and numbered section references | [Build plan](EZBIZI-BUILD-PLAN.md) |
| Tenant identity, reach, view and write ownership | [Ownership contract](OWNERSHIP.md) |
| Implementation, isolation and verification | [Development rules](DEVELOPMENT-RULES.md), [local environment](EZBIZI-DEV-ENVIRONMENT.md) |
| Deployment and rollback | [Deploy](DEPLOY.md) |
| What each version shipped | `client/lib/core/version_history.dart` (the desk writes it); [Release notes](RELEASE-NOTES.md) and [build log](EZBIZI-BUILD-LOG.md) are history (last updated 2026-09-18 and 2026-09-08) |
| Store submissions and signing | [Stores](STORES.md) |
| Module ownership and handoffs | [Module owners](MODULE-OWNERS.md), [agent protocol](agents/README.md), [inbox](agents/inbox/) |
| Mail and connected services | [Mail](MAIL-SETUP.md), [Leads](LEADS.md), [Business Profile](BUSINESS-PROFILE.md), [Reviews](REVIEWS.md) |
| Review findings and remediation | [2026-09-08 review](PROJECT-REVIEW-2026-09-08.md), [implementation plan](superpowers/plans/2026-09-08-review-remediation.md) |
| Feature designs | [Specs](superpowers/specs/), [plans](superpowers/plans/) |
| Incident narratives | [Archived StartUp](history/2026-09-08-startup.md), [superseded reviews](superseded/) |

Keep moving release/test/data counts in STATUS's generated block. Preserve TODO identifiers because code cites them. Add dated change notes without inventing a version or declaring an unverified deployment.
