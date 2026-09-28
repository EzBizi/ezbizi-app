# Draft: telling Google that EzBizi will send automated replies to Local Services leads

**Status:** draft for Jose to send, written 2026-09-28. Nothing has been sent.

## Why this is needed

Jose asked to replace Rodrigo's auto-reply bot with EzBizi Automations: a rule
answers a new Google text lead the moment it arrives. EzBizi cannot do that
today, and should not until Google is told, because of what was filed:

- `ads-api-compliance`, 2026-09-15, and Google's OAuth Trust and Safety team,
  2026-09-16, were both told, in these words: *"The only write is
  appendLeadConversation: one reply a member of staff types and sends to a
  text enquiry."*
- The code enforces that sentence: `LocalServicesLeads::allowsUnattendedReply()`
  returns false, so an automation's reply step skips Google leads. See
  `app/Support/Leads/LeadReplyChannel.php` for the history.

Standard Access was granted on 2026-09-26. Jose's ruling is that new
capabilities are notified to Google about two weeks before they go live.

## What to send

**To:** the same `ads-api-compliance` thread (reply there, so the reviewer has
the history), and the OAuth verification thread if it is still open.

**Subject:** EzBizi (developer token for [our manager account]): update to Local
Services lead replies

> Hello,
>
> This updates our earlier description of how EzBizi writes to Local Services
> leads.
>
> Until now the only write was `localServices:appendLeadConversation`, one reply
> a member of staff types and sends to a text enquiry. We are adding one more
> use of the same call: an advertiser may set up an automated first reply to a
> new text enquiry, written by the advertiser themselves and sent once, when
> the enquiry arrives. It replaces a comparable automated reply the advertiser
> runs today through another tool.
>
> - Only text (MESSAGE) enquiries. Calls and bookings are never written to.
> - At most one automated reply per enquiry. It is never sent to a customer who
>   has already had a reply.
> - The advertiser writes the text and switches the rule on. EzBizi supplies no
>   generated or default wording.
> - Every automated reply is marked as automated in EzBizi's thread and in its
>   audit log, apart from replies a person sent.
> - No other resource is written, and nothing is changed on campaigns, budgets
>   or accounts by this feature.
>
> We plan to enable this no earlier than [DATE, two weeks from sending]. Please
> tell us if anything further is needed.
>
> Thank you,
> Jose Luis Demasi, EzBizi

## What changes in code on that date

1. `LocalServicesLeads::allowsUnattendedReply()` returns true, and the quoted
   sentence in `LeadReplyChannel.php` is replaced with the new filing.
2. The one-reply-per-enquiry limit is enforced where the rule sends, so that
   the letter above stays true.
3. The website claims row about Google writes (`docs/WEBSITE-CLAIMS.md`) is
   checked against the new wording.
