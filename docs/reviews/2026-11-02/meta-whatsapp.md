# Meta and WhatsApp review (2026-11-02)

Measured on production (release 0.0.0513, `27b215107`) with tinker and Graph calls as www-data, the nginx access log, and the database. Every item below was measured today, not recalled.

## 1. Working

- **Meta connections (Triplex Group):** Facebook Ads (Jose Demasi), Facebook Page (A1 Chimney) and Instagram Professional (A1 Chimney) are all `connected`. Each token was checked against Graph and is valid.
- **Messenger leads:** 36 Facebook DM leads in the last 7 days, all named. Webhook deliveries arrive every day (21 on 11-01).
- **Instagram DM leads:** 9 in the last 7 days, all named. Today's backfill filled the @handle on 3 leads and the picture on 2.
- **Lead-ad forms:** 4 in the last 7 days. Each has phone, ZIP and a referral with `campaign_name` ("A1 - Meta Leads - NJ") and `ad_name` ("New Leads Ad"), read today from Graph.
- **One lead per person (form + chat):** in effect since 0.0.0507.
  - The 4 duplicate pairs from 10-29 were merged on 10-29.
  - A fifth pair, Monique Walker, was created before the fix. It was merged today and the deleted row was dumped to `/var/backups/dup-leads-2026-11-02.jsonl`.
- **Ad questions:** a question tapped from an ad is marked `is_ad_question`.
  - 3 older rows were backfilled.
  - None have arrived since the deploy, because no Click-to-Message ad lead has come in since 10-30. All 6 Facebook DM leads since then were typed messages.
- **EzBizi's own WhatsApp numbers:**
  - +1 954-516-2455 (staff bot / reports) sends 8–23 messages a day.
  - +1 877-450-8572 sends 21 a day.
  - No Meta, WhatsApp or Social job has failed in the last 7 days.
- **Webhook subscriptions (done today):** the app is now subscribed to `smb_message_echoes`, `smb_app_state_sync` and `history`, on top of the existing fields (Graph returned `success:true`). Replies typed on the phone and contacts saved in the Business app can now reach Leads.

## 2. Broken

1. **A1 Chimney's WhatsApp (+1 888-519-5311) stopped reaching EzBizi after 2026-10-29 19:24 UTC.**
   - Evidence:
     - `whatsapp_messages` has nothing for phone id 828710493664255 after 10-29.
     - The connection's token (system user 122097828003533370) is still valid, but Graph now answers code 100 / subcode 33 ("does not exist or missing permissions") for the number, the WABA 1816144622650958 and `smb_app_data`.
     - On 10-29 the same calls returned `CONNECTED` and `subscribed_apps: EzBizi`.
   - Why: A-1's business no longer grants EzBizi's app access to that WhatsApp account. The access was removed or replaced on Meta's side, for example in Business Settings › Integrations, or by re-registering the number in the WhatsApp Business app. EzBizi still shows the connection as `connected` because nothing re-checks it.
   - Fix: Jose reconnects (Settings › Integrations › WhatsApp › Connect, then "Connect your existing WhatsApp Business app"). That is also what re-triggers the contacts and chat-history import. See section 5.
   - Code gap I own: a connection that has lost access should show as needing a reconnect instead of `connected`. Not fixed yet; suggestion 1.
2. **Lead-ad form name is always empty (`form_name` is null on all 4 form leads).**
   - Evidence: `GET /{form_id}?fields=name` with the Page token returns code 100 / subcode 33.
   - Why: reading a lead form's own object needs a permission that is not part of the app's current access. The answers, campaign and ad name do arrive.
   - Fix: none needed for the leads themselves. `campaign_name` and `ad_name` already identify the ad. The form name can be added if Meta approves the permission in a later App Review.
3. **New thread fields are not drawn by the app yet.**
   - Evidence: the API sends `attachments` with `?kind=audio|image|video|document`, plus `sent_from_phone`, `ad_question` and `form_answers`, but `git grep` on main finds none of them used in `client/lib`.
   - Effect: a WhatsApp voice note shows as a broken image tile with a number, and phone replies and ad questions look like ordinary bubbles.
   - Owner: UI-UX, for Jose's approval. The data is ready.
4. **Two WhatsApp leads from 10-29 have no name (+5491159102045, +923352516514).**
   - Why: they arrived before names were captured, and their webhook payloads were never stored, so the name cannot be rebuilt. New WhatsApp leads take their profile name automatically.

## 3. Cohesiveness

- Media in the lead thread uses the generic image tile for every file type. Every other channel's attachments are images, so voice notes stand out as broken tiles until UI-UX ships the media bubble.
- The WhatsApp source chip is the same colour as every other source. The WhatsApp-green chip asked for on 10-29 is not on main yet.
- A connection's status ("connected") means different things across Integrations. For Meta it means "we hold a token", not "the token can reach the account" (see Broken 1). Google rows re-check access; Meta rows do not.

## 4. Suggestions (ranked)

1. Re-check each Meta and WhatsApp connection daily: `GET /{phone_number_id}` or `/{page_id}` with its token, and mark it "needs reconnect" plus ring the owner when Meta answers code 100. Payoff: A-1's silent 4-day WhatsApp outage would have been flagged within a day.
2. Keep the raw webhook payload for 30 days (one JSON column, purged by retention). Payoff: names, ad context and coexistence history could be rebuilt after a bug instead of being lost, as happened 4 times this week.
3. A spam filter for Messenger, Instagram and WhatsApp leads. Payoff: about a third of this week's Facebook DM leads are review-removal or SEO pitches ("1⭐ google reviews are killing your business", "Digi Optimizo", "Doctors Map", "Emily Repute Shield"). They show up as new leads and set off alerts.
4. Store the WhatsApp contact's name on every message, not only once the lead exists. Payoff: no unnamed WhatsApp leads, even when the first message is a voice note.
5. Ask for the lead-form read permission in the next Meta App Review round. Payoff: the form's name appears beside the answers.

## 5. Needs Jose

1. **Reconnect A1 Chimney's WhatsApp.** In Settings › Integrations › WhatsApp, press Connect and choose "Connect your existing WhatsApp Business app". Before that, check in Meta Business Settings › Integrations whether EzBizi was removed, and by whom. Until then, nothing sent to +1 888-519-5311 reaches Leads.
2. Should EzBizi hide likely-spam Messenger, Instagram and WhatsApp leads automatically, or only label them (suggestion 3)?
3. Do you want the lead-form read permission included in the next Meta App Review round (Broken 2)?
