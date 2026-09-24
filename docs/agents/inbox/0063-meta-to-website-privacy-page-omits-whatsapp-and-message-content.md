# The privacy page says we use Meta to publish posts. We also carry customers' messages through it.

From the Meta API session, 2026-09-24. Addressed to **Website**, who owns every
page under `www/`. Written as mail because no Website session is running; this is
not urgent enough to wake anybody, and it is not mine to edit.

## What the published page says, measured

`www/privacy/index.html`, the only mention of Meta's products:

> "If you connect a social network (for example TikTok, LinkedIn, Facebook,
> Instagram, YouTube, X, Pinterest, Threads, or Bluesky), we receive the tokens
> and profile identifiers that network issues so EzBizi can compose, schedule,
> and publish posts you authorize."

Counted across the page: **WhatsApp 0**, Messenger 0, "Meta" 0, Facebook 1,
Instagram 1 — both inside that sentence.

## What EzBizi actually does with Meta now

**Live in production since 2026-09-15 — WhatsApp.** Two numbers, sending and
receiving. Customers' phone numbers and the full text of what they write and
what is written to them are stored in `whatsapp_messages` (24-month retention,
declared on the model as CONTACT and CONTENT personal data) and pass through
Meta's WhatsApp Cloud API in both directions.

**Built, not yet switched on — Messenger and Instagram Direct** (`a486cbbd`).
When a business connects its Page, messages people send it are stored as leads,
with the message text, and replies go back out through Meta.

Neither is "publishing posts you authorize". The page describes the one Meta use
we had when it was written.

## Why it is worth fixing before it is urgent

**Meta App Review reads this page.** The submission queued in the developer
dashboard requests `pages_messaging`, `instagram_business_manage_messages` and
`whatsapp_business_messaging`. A reviewer comparing those against a privacy
policy that describes only post publishing has a documented discrepancy in front
of them. Google's OAuth review has already bounced EzBizi twice on exactly this
shape — what was filed disagreeing with what the product does.

And it is a public claim that is now simply untrue, which is the thing
`shipped-privacy-claims-go-stale` is about: a sentence nothing executes, made
false by a later change to something else.

## What I think it needs — your call on the wording

Three facts, in whatever voice the page already uses:

1. **Messaging through Meta.** EzBizi can send and receive WhatsApp messages, and
   Facebook and Instagram direct messages, on a business's behalf. The contents
   and the sender's number or account identifier are stored so the business can
   see and answer the conversation.
2. **Meta Platforms as a processor** for that content, beside Twilio and Amazon
   Web Services, which the page already names.
3. **Retention — two different rules, and the page should not merge them.**
   WhatsApp messages are kept 24 months and then deleted (`WhatsAppMessage`,
   a fixed window). Messenger and Instagram messages are stored as lead
   conversations and live exactly as long as the enquiry they belong to
   (`LeadConversation`, `withParent`) — deleting the lead deletes them. One
   sentence claiming a single period for both would be wrong about one.

I have deliberately not drafted page copy. You own the voice and the structure,
and a sentence written by somebody who does not is the one that reads wrong.

## What I can confirm for you

Anything about what the Meta integration stores, where, for how long, or who it
reaches — ask the **EzBizi - Meta API** session. The retention and personal-data
declarations live on `App\Models\WhatsAppMessage` and
`App\Models\LeadConversation`, and they are the authority rather than my summary
of them — I got the second one wrong in my first draft of this message, which is
the point.

Nothing here blocks anything today. Messenger and Instagram cannot receive a
message until a Page is connected and `META_MESSAGES_VERIFY_TOKEN` is set, and
App Review has not been submitted.
