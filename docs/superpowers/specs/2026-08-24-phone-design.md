# Phone — design

**Date:** 2026-08-24  
**Status:** approved in session 2026-08-24; not yet built  
**Product:** EzBizi customer app (`app.ezbizi.com`)  
**Layouts:** `docs/previews/phone-layouts.html`

This is a new module. It does not copy A1 Tools Twilio webhooks. A1 Tools may be read for what a field team dials; the shape here is EzBizi’s: tenant connections, tab permissions, role grants per number, Refusal sentences, automations declared on day one.

---

## 1. What it is

The organization’s phone system, running on **their** Twilio (or an EzBizi-provisioned Twilio subaccount): numbers, routing, a browser softphone, SMS, voicemail, recordings, and caller identity.

One sidebar tab (`phone`). Three sub-tabs, chips above the page (same strip as Email and HR): **Call** · **Records** · **Settings**. The sidebar already says Phone; there is no second heading.

---

## 2. What it is not

- Not Twilio Studio. Flows are data in EzBizi; we emit TwiML.
- Not Twilio Flex or TaskRouter. A ring group is `<Dial>` of at most ten `<Client>` / `<Number>` nouns.
- Not a second Twilio account on one organization-or-company place. One **connection** per place.
- Not a cascade of **lines**. A company number is not a franchise number. Credentials walk up; lines do not.
- Not storing audio in FileStore. Twilio holds recordings and voicemail; we store SIDs and play through Twilio.
- Not sending SMS before A2P 10DLC (or toll-free verification) is approved on that number. The thread exists; send is refused in a sentence.
- Not placing a call or sending SMS from an automation. Same objection Email records against a rule that writes as the public inbox.
- Not A1 Tools code. Not a modal. Not a console command for a customer.

---

## 3. Settled decisions

| # | Decision |
|---|---|
| 1 | Connection kind: **BYO** (paste Account SID + API key) **or** **provisioned subaccount** under EzBizi’s platform Twilio. Product offers both. |
| 2 | One spec, full surface (not a v1 slice that omits IVR/port/trust). Implementation may still land in ordered tasks. |
| 3 | Numbers sit on **organization, company, or franchise**. No cascade. |
| 4 | Who may use a number is a **role grant**, nested under Phone in Roles (mailbox pattern). |
| 5 | Ringing is Twilio `<Dial>`: simultaneous (default) or `sequential="true"`, max **ten** targets per Dial, then another Dial on `no-answer`. Members are people and/or a role expanded to Client identities (optional PSTN Number). |
| 6 | Available = signed into EzBizi, Phone not DND, `Device.register()` on **this connection**. Internal still rings if they are on another tab. |
| 7 | Live transcription **off** unless turned on **per number**. |
| 8 | Recording **on** by default per number, with a spoken `<Say>` notice. Twilio does not announce; we do. Org/company can switch recording off on the number. |
| 9 | Audio stays on Twilio. EzBizi stores metadata and plays via authenticated Twilio media URLs. |
| 10 | SMS UI exists; outbound refused until that number’s campaign (or toll-free verification) is approved. Inbound still stored. |
| 11 | Internal calling is Voice SDK Client-to-Client on the **same connection**. No PSTN. Cross-connection “internal” is not a Client call. |
| 12 | Flows are EzBizi data → TwiML. Customer never opens Twilio for routing. |
| 13 | Settings has Trust: business profile, SHAKEN/STIR, CNAM, Voice Integrity. We submit; carriers vet; we show status. |
| 14 | After hours is **per number**: voicemail, forward (E.164 or another number on this connection), or closed message then hang up. |
| 15 | Automations: `phone.call.started` `phone.call.ended` `phone.call.missed` `phone.voicemail.left` `phone.sms.received` `phone.sms.sent` `phone.number.broken`. Actions: mark voicemail dealt with; create a job from a missed call. No action that places a call or sends SMS. |
| 16 | Both connection kinds. |
| 17 | Connection at **organization and company** (a territory may have its own Twilio). Not franchise. |
| 18 | Credential walk-up: franchise number → company connection → else org connection. Company number → company connection → else org. Org number → org only. One Voice SDK `Device` per connection the person is granted a number on. Client internals stay on that connection. |
| 19 | Call flow as section 2: transcription? → recording notice? → hours → IVR or ring group → voicemail. Whisper on `<Client url>`. `answerOnBridge="true"`. Hours timezone lives on the schedule. Sequential batches if more than ten members. |

---

## 4. Hierarchy and reach

```
Organization connection?     → org numbers; fallback credentials for companies/franchises with none
 └── Company connection?     → that company’s numbers and its franchises’ numbers (if no further connection)
      └── Franchise          → no Twilio login of its own; numbers still tag the franchise
```

- **Authority never flows upward.** Connecting Twilio on a company is a company-level act. A franchise administrator cannot connect the company. An org administrator can connect the organization.
- A number’s **Twilio account** is the first connection on the walk-up. We never place a Georgia call on Connecticut’s Account SID.
- A person assigned at organization who holds grants on both a CT number and a GA number registers **two Devices** if CT and GA are different connections. The Call desk is still one screen.
- Reach is necessary and not sufficient: assignment says where they stand; the **number grant** says which lines they may answer or dial as.

---

## 5. Connection

One row per place (`organization` | `company`). Kind `byo` | `subaccount`.

**BYO.** Paste Account SID, API key SID, API secret. Verify with `GET /2010-04-01/Accounts/{Sid}` using those credentials. Refuse in a sentence if the key cannot. Secret encrypted, masked, `mayReveal` false — same three holds as `Mailbox`.

**Provisioned.** Platform credentials live in box `.env` (`TWILIO_PLATFORM_ACCOUNT_SID`, `TWILIO_PLATFORM_API_KEY_SID`, `TWILIO_PLATFORM_API_SECRET`), never git, administered as `development@ezbizi.com`. `POST /2010-04-01/Accounts` with `FriendlyName` = the organization or company name, then create an **API key on the subaccount**. Store subaccount SID + key. Usage bills **EzBizi’s** Twilio balance. Invoicing that onward is **not** this module; Settings must say that EzBizi is the Twilio customer on this path.

**On success (either kind).** Create one **TwiML App** on that account: Voice URL and status callback point at EzBizi. Store `twiml_app_sid`. Incoming numbers use that App or the same Voice URL.

**Webhooks** (public HTTPS):

```
POST /api/v1/phone/voice/{organization}/{connection}
POST /api/v1/phone/sms/{organization}/{connection}
POST /api/v1/phone/status/{organization}/{connection}
POST /api/v1/phone/transcription/{organization}/{connection}
POST /api/v1/phone/recording/{organization}/{connection}
POST /api/v1/phone/port/{organization}/{connection}
```

Validate `X-Twilio-Signature` with **that connection’s** token. A global token is a tenant-boundary break.

**Disconnect.** BYO: drop keys, leave numbers in *their* Twilio (we stop answering webhooks; numbers’ VoiceUrl should be cleared so calls do not hit us). Provisioned: suspend or close the subaccount only after an explicit Settings act; Twilio deletes closed subaccounts after 30 days. Say that in the refusal/confirm sentence.

One connection per place. Switching BYO ↔ subaccount is a migration (move or port numbers), not a second row.

---

## 6. Numbers

Twilio `IncomingPhoneNumber` is the source of truth for E.164 and capabilities. We store SID, connection, scope (`organization` | `company` | `franchise`), friendly name, which flow, recording flag, transcription flag, after-hours, language, emergency address status, messaging-service SID, campaign status.

**Buy.** `AvailablePhoneNumbers` search (country, area code, capabilities voice+SMS) then purchase onto the connection resolved for the chosen scope.

**Port in.** Porting API (US, public beta): portability check, upload bills, e-LOA to the authorized representative, status webhooks. UI is a request + status, not an instant number.

**Emergency.** US numbers require an emergency address on the IncomingPhoneNumber. Settings always shows whether it is set. If Twilio refuses a buy or an inbound without it, we show Twilio’s sentence. We do not skip the address to make the button work.

**Grants.** `phone_number_grants`: `organization_id`, `number_id`, `role_id`. Unique `(number_id, role_id)`. Roles editor composes `phone.view` with this organization’s numbers. Not a platform `permissions` row.

**Delete / release.** Release in Twilio and remove our row only with `phone.edit`. Recordings already taken stay on Twilio until §9 erasure.

---

## 7. Call flow (TwiML we emit)

A **flow** is JSON nodes, attached to a number. Executed live on each webhook (changing a flow affects the next webhook, not a call already bridged).

```
inbound
  → <Start><Transcription> if number.transcribe
  → <Say> recording notice if number.record
  → hours (our clock)
       closed → number.after_hours: voicemail | <Dial><Number> forward | <Say> closed + hangup
       open   → IVR <Gather> if the flow has a menu
                 else ring group
  → Dial action no-answer → voicemail or next batch of ≤10
```

Outbound from the desk skips IVR and hours. Transcription and recording notice still follow the **caller-ID number**.

**Hours.** Named schedule (week grid + exceptions). Timezone **on the schedule**. Franchise numbers default that franchise’s timezone (`franchises.timezone`, default `America/New_York`). Company/org: administrator sets it. Wall clock (TODO #20).

**IVR.** `<Gather>` digits. Prompt is tenant text or uploaded audio. Digit → next node (menu, ring group, voicemail, hang up, Dial a number on this connection). Timeout / `#` as configured on the menu.

**Ring group.** Expand role + people → Client identities of those **available on this connection**, plus optional PSTN. Cap ten per `<Dial>`. Remainder is a following Dial on `no-answer`. `answerOnBridge="true"`. Timeout default 30s (Voice SDK legs max 60s). Whisper: `<Client url>` TwiML that `<Say>`s the customer name, then connects. Overflow: voicemail, another group, or forward.

Zero available members → skip Dial, go overflow immediately.

**Recording.** Default on. `<Say>This call may be recorded.</Say>` then `<Dial record="record-from-answer-dual" recordingStatusCallback="…">`. Dual channel. Off: no `record`. Legal consent is our `<Say>` plus the tenant’s policy; Twilio only records.

**Voicemail.** `<Say>`/`<Play>` greeting, `<Record playBeep="true">`. `recordingStatusCallback` stores SID on the call row. Automation `phone.voicemail.left`.

**Transcription.** Default off. On: `<Start><Transcription statusCallbackUrl intelligenceService inboundTrackLabel outboundTrackLabel>`. Live events `transcription-content` feed the Call pane. Persist for Records. AI/ML addendum. Fork budget: this uses one or two of four forks per call.

**Language.** Number setting. `Say`/`Gather` use it. Strings from `ui_strings` (en+es seeded).

---

## 8. Softphone (Voice JavaScript SDK)

Flutter **web** drives `@twilio/voice-sdk` via JS interop. There is no first-party Flutter web Voice SDK. Load the SDK from the web client; mint Access Tokens on the server (API key of **that connection** + TwiML App SID + identity).

- Identity: the user’s id in alphanumeric + underscore (mobile SDK constraint, used on web too). Unique per Twilio account.
- `Device.register()` to receive. Unregistered may still place outbound.
- Outbound `Device.connect()` → TwiML App VoiceUrl → we `<Dial><Number>` (PSTN) or `<Dial><Client>` (internal).
- `callerId` on outbound Dial must be a number on **this connection** that the person is granted.
- Incoming PSTN → number VoiceUrl → flow above → Dial Clients.
- Multiple connections ⇒ multiple Device instances, one UI.
- **Presence is ours.** Twilio does not list registered Clients. The desk reports `registered` / `dnd` / `on_call` for each connection it holds. Teammates lists read that, not Twilio. Stale after a short TTL if the tab dies.

Native iOS/Android Voice SDKs are a later client. Same tokens and TwiML. Mobile **browsers** are a poor softphone (background); we do not pretend otherwise.

**Greetings and IVR audio** go through FileStore (same as other tenant files), not Twilio Assets. `<Play>` URLs are our signed file URLs.

---

## 9. SMS

Programmable Messaging. Inbound webhook stores the message (from, to, body, SID, number_id). Thread on the Call desk is **this customer’s E.164** against **this number**.

Outbound: Messaging Service + A2P 10DLC Brand + Campaign on that connection (US local). Toll-free: separate verification. Until approved, send returns a Refusal sentence. Inbound still stored (10).

Direct vs ISV registration follows connection kind (`byo` vs `subaccount`). Settings walks the API; we do not send them to Console as the only path.

---

## 10. Trust, lookup, operations

**Trust (13).** Settings → Trust, per connection: Primary/Secondary customer profile (path depends on BYO vs ISV), SHAKEN/STIR product, CNAM, Voice Integrity. Status is Twilio’s (pending / twilio-approved / rejected) shown as sentences. Branded Calling (`callReason` on Dial) when the product is approved; US T-Mobile/Verizon mobiles only — say so.

**Lookup v2.** On inbound, before the customer card: valid, line type, CNAM, reassigned, quality if the connection has Lookup enabled. Paid per add-on; Settings can turn packages off. Match to `customers.phone` in the current reach.

**Operations.** Number broken (webhook 5xx, invalid number, suspended subaccount) raises `phone.number.broken` once per break, same as a mailbox. Voice Insights stay in Twilio; we show call status and DialCallStatus on the record.

---

## 11. Records we store

Not message bodies of email. Phone **does** keep:

| Row | What |
|---|---|
| Call | SIDs (parent/child), direction, from, to, number_id, user_id if answered, status, duration, recording SID, transcription SID, flow snapshot id |
| SMS | SID, direction, from, to, body, number_id, customer_id if matched |
| Voicemail | recording SID on the missed call |

Play recording: server fetches Twilio media with the connection’s credentials and streams to the client. Never a world-readable Twilio URL in the browser.

§9: recordings and SMS bodies are personal data. Erasure deletes Twilio recordings via API and our rows. Retention: follow existing expire-data once a `StatesRetention` is declared (voicemail/SMS shorter than “forever”; exact window is the org’s existing audit/data policy unless Phone adds a setting — default **3 months** to match audit until a Phone-specific setting exists).

---

## 12. Permissions

Module actions: **`view`** and **`edit`**. (Today the stub is `view` only.)

| Action | What it is |
|---|---|
| `phone.view` | Tab, Call desk, Records for **granted** numbers |
| `phone.edit` | Settings, connect/disconnect, buy/port/release, flows, hours, ring groups, Trust, 10DLC, recording/transcription flags |

Per-number grants compose with `view` the way inboxes do. A person with `phone.view` and no number grants sees the tab and a sentence that no lines have been offered. `edit` without a number grant still reaches Settings for connections they may write (scope rules), not other companies’ keys.

Administrator holds the tab implicitly, same as Inspection.

---

## 13. UI

**Call.** Wide: left teammates (available on the **current** connection), centre dial (top) + live transcript (bottom), right customer card + SMS thread. Current connection/number is a control on the desk (which line I am). DND is a control, not a modal. Incoming: in-page banner + ringtone, Accept / Decline — **no modal dialog**.

At **375px**: one pane at a time (`teammates` | `pad` | `person`), same pattern as Email’s `_Pane`. Back control. Touch 44px. No hover-only.

**Records.** List + detail (25/75). Filters: calls, voicemail, SMS; number; date. Open is a panel, not a popup. Play in the panel.

**Settings.** Left list of sections, right the form (stacks at 375px). Sections: Connections, Numbers, Call flows, Hours, Ring groups, Voicemail greetings, Trust, Messaging (10DLC), Lookup.

Recommended Call-desk layout is **1** in `docs/previews/phone-layouts.html`. Owner picks by number if they want another.

---

## 14. Automations

Declared on `ModuleDefinition` the same day as the module. No subject class (events are about a call, a message, or a number — same cost as HR: `update_field` refused at save).

**Raised:** the seven in decision 15, from the write path / webhook, outbox, never from the request thread as a dispatch.

**Not raised:** none of those seven may be marked `isRaised: false` if the webhook exists. If a trigger cannot fire yet, omit it or say why.

**Actions:** `phone.voicemail.mark_dealt` (changes EzBizi only). `phone.missed.create_job` (creates a job in reach, does not call anyone). No send, no Dial.

---

## 15. Client and backend shape

**Backend**

- `twilio/sdk` PHP client, per-connection credentials, never the platform key for tenant calls.
- Controllers under `app/Http/Controllers/Api/Phone/`.
- Policies with Refusal sentences.
- Models: `PhoneConnection`, `PhoneNumber`, `PhoneNumberGrant`, `PhoneFlow`, `PhoneHoursSchedule`, `PhoneRingGroup`, `PhoneCall`, `PhoneMessage`.
- Webhooks unauthenticated by Sanctum; authenticated by Twilio signature + connection lookup.
- Access token endpoint: Sanctum, `phone.view`, returns JWT for the requested connection.

**Client**

- Replace `ComingSoonScreen` in `module_screens.dart`.
- `client/lib/screens/phone/` — `phone_screen.dart` (TabBarLike), `call_tab.dart`, `records_tab.dart`, `settings_tab.dart`.
- Palette only through `EzBiziTheme`. No literal colours.
- Tabs built once opened, state kept (HR/Email).

**Tests**

- MySQL. Never assert JSON key order.
- Walk-up of connections; grant composition; Dial builder caps at 10 and skips unavailable; hours in named timezone; webhook rejects bad signature and wrong account; recording notice present iff number.record; SMS send 403 with sentence when campaign unapproved; BYO secret never in JSON; subaccount create uses platform key only inside the provisioner.
- Widget tests for Call panes and 375px stack; no `Coming soon` on a granted Phone tab.

---

## 16. Platform leftover (Mia / console)

Not in git:

- EzBizi Twilio account as `development@ezbizi.com` (path B).
- Platform API key on the box `.env`.
- Webhook URLs on TwiML Apps point at `https://app.ezbizi.com`.

Same class as SES and Workspace.

---

## 17. Privacy and comments

Credentials: encrypted column, `MasksSensitiveValues`, `mayReveal` false.

Recordings/SMS: named in the §9 inventory.

Decision comments only where somebody would do the obvious thing and be wrong: walk-up vs cascade, Client identity per account, Dial max 10, recording notice is ours, webhook token is per connection.

---

## 18. Recommended Call-desk layout

Layout **1** in the preview: three columns, pad above transcript, teammates left, customer + messages right. That is the owner’s sketch. Layouts 2–3 are alternatives if 1 is rejected.

375px is not an alternative; every layout must stack.
