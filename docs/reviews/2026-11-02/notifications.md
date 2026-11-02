# Notifications — review 2026-11-02

Measured on production on 2026-11-02, covering the last 7 days.

## 1. Working

- **Bell rows:** 31,983 written in 7 days. Sources: automation 31,895, system 64, alert 20, work_hours 4.
- **Flood cap:** the busiest rule × person pair this week wrote 1 row. Last month a single rule loop wrote 884 rows in 30 minutes.
- **Deep links:** every subject type seen this week maps to a module. Lead 31,007; Mailbox (opens the message) 416; TimeClockCorrectionRequest 54; Alert 20; Automation 9; PtoRequest 4 (Work Hours › PTO); Suggestion 1. The 472 rows with no subject are missed calls, which open through their event's call id.
- **Phone push:** registered devices are 44 Android and 31 iOS. No "Push not sent" lines in today's log.
- **Three-line format:** "EzBizi - Company - Branch" / what happened / content, on the push, bell row, desktop toast and email subject.
- **Per-person settings in use:** "Alert me" is off for 5 people. "Notify me about" is set for 1 person (Bar: Everdoors and its sub companies). Missed calls now carry company_id, so that filter covers them too.
- **Urgent (red) rows and the Android urgent channel:** in use by the Complaint call rule and by alerts.

## 2. Broken

- **None found in what this module owns.**
- **Not mine; the owner is SES/signup:** 5 failed `EmailVerificationLink` jobs on 10-28 and 10-29. All are `451 4.4.2 Timeout waiting for data from client` from the SMTP relay, a known pattern (a-failed-smtp-send-keeps-its-connection). The worker needs a restart after a 451, or the transport needs to reconnect per message.

## 3. Cohesiveness

- The bell panel row now has a small header caption ("EzBizi - Company - Branch") above the title. No other list in the app has an overline, and UI-UX never reviewed it.
- "Alert me" and "Notify me about" live in the bell panel. Every other personal setting lives under My Account. They should either move or be linked from there.

## 4. Suggestions (ranked)

1. **Cut automation noise.** 29,975 of the 31,895 automation rows (94%) were never read. One person received 173 in one day. Payoff: people read the bell again.
   - Group same-rule rows per person into one row with a count ("12 new leads").
   - Auto-mark rows read once their lead is opened anywhere.
2. **Mark a row read when its record is opened from a deep link on another device.** Payoff: unread counts that agree across phone and desktop.
3. **Show a weekly "you muted N notifications" line** to people with a company filter. Payoff: a filter set and forgotten doesn't silently hide things.
4. **Retire the 30-day-old unread backlog** (auto-read after 30 days). Payoff: badge counts mean something.

## 5. Needs Jose

- Should same-rule notifications be grouped into one row with a count (suggestion 1)? It changes what people see in the bell.
- Should "Alert me" and "Notify me about" move to My Account, or stay in the bell panel where you first asked for the switch?
