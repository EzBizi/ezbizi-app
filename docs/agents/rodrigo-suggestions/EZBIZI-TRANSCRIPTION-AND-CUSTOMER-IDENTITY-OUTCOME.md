# Outcome: transcription, call understanding, and customer-name updates

Suggestion 01a11778-dd8c-739a-b91e-2cadefd89de3 (Rodrigo Mena, 2026-10-07), handoff
[EZBIZI-TRANSCRIPTION-AND-CUSTOMER-IDENTITY-HANDOFF.md](EZBIZI-TRANSCRIPTION-AND-CUSTOMER-IDENTITY-HANDOFF.md).
Agreed between Reports and Google API/Leads on 2026-10-09.

**§2, one interpretation authority:** EzBizi is that authority. A1 Leads/A-1 Tools was switched off on
2026-09-28, so EzBizi does not consume another interpreter. It transcribes on its own server (Whisper) and
reads calls with Grok.

**§4, appointment semantics (Reports, done in 84c9f8a4):** the Performance report's "why did this new caller
not book" labels now follow the handoff:

- A quote or an offered time is not a booking.
- Declining, "I'll call back" or shopping around is not booked.
- An accepted estimate or inspection visit is booked, even if repairs are undecided.
- A cancellation later in the same call undoes the booking.
- The transcript is passed as data, not instructions.
- A model failure was already treated as pending work: it is logged and retried, never stored as an answer.

This matches Leads' "Lost" (declined, or a service we do not do).

**§1, speech recognition (benchmark by Reports; Leads owns `deploy/whisper-transcribe.py` and decides):**
The same 5 real calls (57 s to 13 min, 1,474 s of audio in all) were run through today's settings and
through the handoff's decoding settings: beam 5, VAD 500/200 ms, no previous-text conditioning,
no_speech 0.7, hallucination-silence 2.0, and the dispatcher roster in the prompt.

Results, measured on the app box with distil-large-v3, int8, 4 threads, nice 19:

- **Speed:** the same. Today's settings took 223 s; the handoff's took 211 s.
- **Words:** within 2% of each other (2,667 vs 2,633).
- **Differences:** mostly punctuation and digits written as words. The handoff's settings also dropped
  part of a spelled-out name in one call and garbled a form name in another. No error that today's
  settings made was fixed.

Conclusion: no measurable gain on our model, so the script stays as it is. The handoff's model,
large-v3-turbo, is not installed on the box. Trying it means downloading it, and that is Leads' decision.
Transcripts were not copied anywhere; the comparison ran on the box and was deleted afterwards.

**§3 and §5, customer names (Google API/Leads):**

- Today, Grok (LeadInsights) fills a lead's name and ZIP only when they are blank. It never overwrites
  a name and never writes to the customer or to Workiz; a failure is retried. That covers the
  blank → name, never-erase and never-overwrite rows of the merge table.
- Follow-ups owned by Leads: first name → full name enrichment, spelled corrections, a conflict/review
  record, and the shared-phone guard at the customer level.
