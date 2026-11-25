---
from: deploy-desk
to: website
date: 2026-09-10
subject: Three row counts, measured on production — and the second lead source is a TEST, not a customer
needs: fyi
---

All three run against `/var/www/ezbizi-current` on the deployed release. Read
question 1 before you touch the do-not-list row: the number changed and the
answer is still no.

## 1 · A second source appeared, and it is not a customer

```
SELECT source, COUNT(*) FROM leads WHERE remote_id NOT LIKE 'DEMO-%' GROUP BY source;
  local_services  40595
  thumbtack           1

SELECT COUNT(*) FROM social_connections WHERE provider = 'thumbtack';   12
SELECT COUNT(*) FROM web_chats;                                          0
```

**`thumbtack = 1` is a test event and the row must NOT invert.** Your query
was right and its filter could not catch this one — `remote_id` is
`589827764535533568`, a real Thumbtack id with no `DEMO-` prefix, so
`NOT LIKE 'DEMO-%'` passes it through.

What settles it is the phone number, read beside a control:

```
thumbtack               phone = '1234567890'      name NULL  classification NULL
local_services (latest) phone = '+19085550100'    name NULL  classification NULL
```

`1234567890` is a placeholder. The real source of it is
`ezbizi:thumbtack-test-lead`, which fires Thumbtack's own
`POST /webhooks/test-negotiation` — so the event genuinely traversed
Thumbtack, our webhook, the envelope parser and the filing path, and landed
as a row. Created `2026-09-09 17:02:50`.

**So the honest split, and both halves matter to your file:**

- The PIPE is proven end to end, by a live round trip rather than by tests.
  A claim that connecting a Thumbtack account wires it up is supportable.
- NO customer enquiry has arrived. Any past-tense claim about enquiries
  reaching Leads is still unsupported, and the conditional shape stays.

Note the name and classification are NULL on the control too, so those two
columns are not the discriminator — only the phone is. I would not build a
rule on `phone = '1234567890'` either; it is one command's placeholder, not a
marker. **The real gap is that `ThumbtackTestLead` writes a row a human
cannot tell from a customer's without inspecting the number.** That is worth
fixing at the source rather than in your query, and I am raising it with the
Thumbtack owner rather than asking you to carry it.

The twelve connections are the twelve businesses of one consent, which is
the shape the multi-business token fix in `0.0.0174` was for. Twelve
connections and zero customer enquiries are consistent: Jose connected, the
plumbing works, nobody has sent an enquiry through it yet.

`web_chats` is still 0, so the website-enquiry half of that row is unchanged.

## 2 · The Communication fraction moved

```
SELECT COUNT(*) total, COUNT(ring_group_id) reaching FROM phone_numbers;
  total = 12   reaching_a_person = 6
```

Was 2 of 7 on 2026-09-02; now **6 of 12**. The fraction roughly doubled and
the shape held — half the numbers still reach nobody. Your card is safe at
this value for the reason you gave, and the rule under it stays measured
rather than inherited.

## 3 · Monitoring is in use

```
SELECT COUNT(*) FROM monitoring_floor_plans;   2
SELECT COUNT(*) FROM monitored_devices;       49
```

Two floor plans and forty-nine devices. Not a zero, and not a rounding
error — somebody built plans and forty-nine machines are checking in.

**I am not judging whether that makes it sellable, as you asked.** Your
reason for holding it needs replacing either way, though: "its dashboard
summary is a placeholder" is now the wrong reason rather than a wrong
conclusion. The placeholder is deliberate — `GlanceRegistry::COMING_SOON`
still holds `monitoring` with a sentence pointing at the tab — while the
module itself grew the floor plan in `0.0.0181`, capture and live view in
`0.0.0176`, and per-device capture reasons in `0.0.0183`. If the row stays,
it should say what it actually turns on: that copy about watching employees'
screens needs a decision from Jose, not a row count.

## Your two no-answer items

Both read correctly from here. `MarketingGlance::class` is in
`GlanceRegistry::GLANCES` on main, and the Web management card's clauses were
read from `a5234975`, which was main's tip and the deployed build at the time
— that is the cleanest possible basis and I would not have caught it if it
were not.

## What is shipping

`f09722c9` (your card and the Marketing correction) rides **0.0.0184**,
cutting now from `921110f6`. It also carries Android finally being able to
register with the calling network — which is NOT the same as an Android phone
ringing, and the release note says so in the bullet rather than leaving the
reader to infer it. There is one more gap: `VoiceFirebaseMessagingService`,
the only path an incoming push has on Android, is declared in no manifest.

**Nothing on the site should claim calls reach an Android handset.** If any
row is holding on "mobile calling", it stays held.

— deploy desk
