---
from: training
to: hr
date: 2026-09-09
subject: Jose approved a Training card on the HR record screen — it needs an HR owner
needs: action
---

`docs/SESSIONS.md` has no HR row, so this is a file rather than a message: a file
waits for whoever arrives, a message needs somebody already there.

**Jose approved this on 2026-09-09.** Training's spec §10 says the HR record
screen renders a Training card. It is unbuilt, and it is HR's screen, so it is
not Training's to place.

## What already exists, so nobody re-derives it

`GET /training/records?user=<id>` — **live in production** at 0.0.0169, gated
`training.monitor`. Returns one person's training: what they were assigned, what
they passed or failed, their scores, and when a pass runs out.

**It has no consumer.** No HR controller and no HR screen references Training at
all; I grepped both sides before writing this.

## Three constraints that are settled, and why

1. **It never says "certification".** HR's dashboard already has an *Expiring
   certifications* panel counting licence dates the employee typed at onboarding
   — theirs to renew. Training counts our own test passes lapsing — ours to
   re-assign. Different table, different fact, **different person responsible for
   acting on it**. If this card lands on the HR screen the two sit together, so
   the distinction stops being academic. Training says **"due for retaking"**.

2. **"Due for retaking" means ALREADY LAPSED, permanently** — not "lapsing soon".
   HR's panel is a 60-day lookahead; Training's is a failure in progress. The
   UI/UX owner ruled against making them symmetric: it would hide that Training's
   is the one where the harm has already started. If a lookahead is ever wanted it
   is a NEW field, never a redefinition of this one.

3. **Training writes nothing into HR's tables.** Training owns the data, HR reads
   it. Decided so a result cannot exist in two places and drift.

## One thing to know before designing it

The endpoint is bounded by reach over the **person**, not over the content — an
audit found two cross-franchise disclosures of named people's test results and
that was the fix. So a manager viewing a record they can reach sees the card; one
who cannot gets a refusal carrying a sentence. **Draw the refused state, not only
the populated one.**

## What is wanted

An owner for HR, and a design from the UI/UX owner
(`local_f82e74ac-d808-40cd-bc95-ce473619183a`, already told). None of Training's
four approved layouts is the right shape — this is one person's history, and those
are a library, a player, a per-guide dashboard and a builder.

Nothing is blocked. Training is complete and live. Ping
`local_d30258ab-af2b-4b1b-9cf5-b0926fbe06ec` when a design lands and I will build
the client half against the endpoint that already exists — or hand it over
entirely if it belongs on HR's side.

— Training
