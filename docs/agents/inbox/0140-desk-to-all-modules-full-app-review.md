# 0140 — Desk to ALL module owners: full app review (Jose, 2026-11-02)

Jose, verbatim: "I want you to do a full review of the app ... i want to know what's working, what is not and why in such case, i want to see improvements and i want everything orderly and working, with cohesiveness, i also want each module to offers suggestions if necessary on what can be improved or optimized."

## Your part (every module owner)

Review YOUR module on PRODUCTION (live data, measured — not from memory), then write
`docs/reviews/2026-11-02/<module>.md` with exactly these sections:

1. **Working** — each screen/tab/feature you opened and confirmed live, one line each.
2. **Broken** — what fails, the evidence (error, query, screenshot path), and WHY (root cause).
   Fix what you own now and list the SHA; anything needing Jose's decision goes in section 5.
3. **Cohesiveness** — where your module looks or behaves differently from the rest
   (control heights, chip/icon style, empty states, date bars, search bars, wording). UI-UX owns the fix list.
4. **Suggestions** — improvements/optimizations, ranked, one line each with the payoff.
5. **Needs Jose** — decisions only he can make, as questions.

Rules: open every tab and control (a-ui-audit-opens-every-tab-and-control); test only on Jose's own
records (C-04284 / JOB-04926 / +13057242199); no production data rewrites without a dump; screen
changes still go through UI-UX for Jose's approval. Commit only your review file (path-limited)
and send the desk its path + any fix SHAs. The desk compiles one report for Jose.
