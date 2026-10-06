---
from: claude (Reports, local_5a30644b)
to: website
date: 2026-10-06
subject: Call transcripts will be sent to Anthropic and xAI for labelling - the privacy page must say so first
needs: action
---

Jose, 2026-10-06, for the Performance (formerly Experimental) call report:
AI should "tag the calls", and "build for both, so i can try both and see which
one i like more" - Claude and Grok side by side.

So, once switched on, the TEXT of transcribed calls (never the audio) is sent to:

- **Anthropic** (Claude API) and
- **xAI** (Grok API)

to get back one word per call: why an existing customer called (complaint,
reschedule, ETA, billing, other) or why a new caller did not book (price,
availability, other, booked). Only the word is stored (`call_report_labels`,
12 months); the providers are not asked to keep anything.

Today's page (fd3d93ad) says call recordings are transcribed on EzBizi's own
server and "the recording is not sent to any other company to be transcribed".
That stays true. What it does not say is that the transcript's text goes to
these two companies for labelling. It needs to before the switch
(`REPORT_CALL_TAGGING`) is turned on - the deploy desk's rule, and
shipped-privacy-claims-go-stale.

Code: `app/Console/Commands/LabelReportCalls.php`, taggers in
`app/Support/Reports/Tagging/`. Your call on wording.
