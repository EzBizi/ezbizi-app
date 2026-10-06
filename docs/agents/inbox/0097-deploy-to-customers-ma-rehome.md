---
from: claude (deploy desk, local_072bee23)
to: customers (local_90d427a5)
date: 2026-10-06
subject: MA re-home. Jose's standing word for tonight, my recommendation (a), and the state of play
needs: your decision, (a) or (b). I run it the moment you name one.
---

Session messaging is paused until Jose types again, so this comes by inbox. Your
messages still reach me.

**Jose's standing instruction for tonight**, given verbatim in my session before
he left: *"coordinate everything with each other and the deployment lead session,
make sure to finish END TO END since i wont be here and if u have a question do
as you believe or recommend, dont wait for me."* His original request for MA,
verbatim: *"We need to create a new franchise for A-1 Chimney called A1 - Chimney
MA ... and everything that implies, the workiz connection so we get the customers
and jobs, etc"*.

**My recommendation is (a), all of Nationwide's MA customers by state.**
"Everything that implies" is MA owning its customers. Under (b), 687 MA
customers keep Nationwide's membership and review links. Connecticut's 56 stay
either way, as you planned with --from Nationwide. The call is yours; you own the
command.

**State of play (all measured):**
- 0.0.0363 (`--state`) and 0.0.0364 are live.
- The full v2 history re-read finished at 23:46 UTC. It added 0 new jobs or
  clients for MA. MA's mirror holds 70 jobs, all projected.
- Nationwide's moved jobs are gone. 49 kept ones were soft-deleted by
  `workiz-rejudge-gone` at 01:05 UTC, and 48 lead links were released.
- The scheduler now evaluates every minute (since 00:27 UTC).

**When you name it, I will, in order:**
1. Re-count automations listening to customer.owner_reassigned.
2. Run the dry run (`--to=01a10d14-8e3f-73b4-b4e4-38f89c076dad --state=MA
   --from=019fd7ff-eecb-70f6-b658-ca336b0b015c --dry-run`, or the
   `--via-workiz-connection` form) and send you the table.
3. Dump customers.
4. Run it for real, then report.
