# Meta App Review — what to submit, and the words to submit it with

App: **EzBizi** (`1750581562857402`), Business `1378090931119436`
Rewritten 2026-09-28 from the code as it stands (the 2026-09-01 version predated
Messenger, Instagram messages and WhatsApp, and told you to remove them).

---

## 1. What is in the submission, and what uses it

| Permission | Used by | Test call |
|---|---|---|
| `pages_show_list` | `FacebookOAuth::identify()` — lists the Pages to connect | none required |
| `pages_read_engagement` | `FacebookPublisher::permalink()` — "View post" link after publishing | **needed** |
| `pages_manage_posts` | `FacebookPublisher` — publishes to the Page | **needed** |
| `pages_manage_metadata` | `SubscribeMetaMessages` — subscribes the Page to message webhooks | **needed** |
| `pages_messaging` | `MetaMessages::send()` — replies to Messenger enquiries in Leads | **needed** |
| `instagram_business_basic` | `InstagramOAuth::identify()`, `InstagramPublisher::permalink()` | none required |
| `instagram_business_content_publish` | `InstagramPublisher` | **needed** |
| `instagram_business_manage_messages` | Instagram DMs into Leads, replies, webhook subscription | none required |
| `threads_basic` | `ThreadsOAuth::identify()`, `ThreadsPublisher::permalink()` | **needed** |
| `threads_content_publish` | `ThreadsPublisher` | **needed** |
| `whatsapp_business_messaging` | `WhatsAppCloud` — templates, replies, staff assistant | complete |
| `whatsapp_business_management` | `WhatsAppCloud::templates()` — template picker in Automations | complete |
| `public_profile` | default | — |

**Remove from the submission** (the app never requests or calls them; a
permission the screencast cannot show is the usual rejection):

- `instagram_manage_comments` — Facebook-Login Instagram API; EzBizi uses
  Instagram Login, and it also requires `instagram_basic`, which is not requested.
- `business_management` — no call in the code needs it.

"Test call" = Meta's *Testing* page. A call counts when EzBizi itself makes it
with a connected account; it can take 24 hours to show and lasts 30 days.
Recording the screencasts below makes every one of them.

---

## 2. The "Allowed usage" description for each

### pages_show_list

> EzBizi is business management software for home service companies (chimney
> sweeps, HVAC, plumbing). After a business owner connects their Facebook
> account, EzBizi calls /me/accounts to list the Pages they manage so they can
> choose which ones to connect — many of our customers run a main company Page
> and Pages for separate branches. The chosen Pages' ids and Page tokens are
> stored against that company. Without this permission there is no way to know
> which Page to publish to or receive messages for.

### pages_read_engagement

> After EzBizi publishes a post to a connected Page, it reads that post's
> permalink_url (GET /{post-id}?fields=permalink_url) so the Social Media screen
> can show a "View post" link beside the sent post, letting the owner open and
> check what went out. EzBizi does not read followers, comments or analytics.
> Meta also requires this permission alongside pages_manage_posts.

### pages_manage_posts

> A business owner writes one post in EzBizi's composer — a seasonal chimney
> safety reminder, a photo of finished work — chooses which connected accounts
> it goes to, and publishes it now or schedules it. For Facebook, EzBizi
> publishes to the selected Page's /feed (text), /photos or /videos using the
> Page token obtained at connection. EzBizi never posts to a Page the user has
> not connected, and never posts without an action the user took.

### pages_manage_metadata

> When a business connects a Page, EzBizi subscribes the app to that Page's
> messages webhook (POST /{page-id}/subscribed_apps, fields messages and
> messaging_postbacks). That is what makes customers' Messenger messages to the
> business arrive in EzBizi's Leads module; without it a connected Page receives
> nothing. EzBizi changes no other Page settings.

### pages_messaging

> Customers who message a business's Facebook Page on Messenger appear in
> EzBizi's Leads module as enquiries, alongside the business's other lead
> sources. A staff member opens the enquiry, reads the conversation, and replies
> from EzBizi; the reply is delivered in Messenger with messaging_type RESPONSE.
> EzBizi only answers people who wrote first, only within Meta's 24-hour window
> (it refuses outside it and says why), and never sends promotional messages.
> Businesses may also set an automatic first reply in EzBizi's Automations,
> which is marked as automatic in the thread.
>
> To reproduce: sign in to EzBizi, Marketing › Social Media › Connect Facebook,
> connect a Page. From another Facebook account, message that Page. Open Leads —
> the message appears as a new enquiry. Open it, type a reply, Send; the reply
> arrives in the Messenger conversation.

### instagram_business_basic

> After a business owner connects their Instagram professional account through
> Instagram Login, EzBizi reads the account's user_id, username and name so the
> app shows which account is connected, and uses the account id to publish and
> to receive its messages. After publishing, EzBizi reads the post's permalink
> for a "View post" link. EzBizi does not read followers or insights.

### instagram_business_content_publish

> From the same composer used for every network, the owner attaches a photo or
> video and publishes or schedules it; EzBizi creates a media container on their
> connected professional account and publishes it. Their own account, their own
> content, one explicit action each time — no automated or bulk posting.
> Instagram needs a photo or video, so EzBizi refuses a text-only Instagram post
> before sending it and says why.

### instagram_business_manage_messages

> Direct messages sent to a business's Instagram professional account arrive in
> EzBizi's Leads module as enquiries. Staff read the conversation and reply from
> EzBizi within Meta's 24-hour window; the reply is delivered in the Instagram
> conversation. On connection EzBizi subscribes the account to its messages
> webhook. EzBizi only answers people who wrote first and never sends
> promotional messages.

### threads_basic

> After a business owner connects their Threads profile, EzBizi reads the
> profile id and username to show which profile is connected and to publish to
> it, and after publishing reads the post's permalink for a "View post" link.
> EzBizi does not read their threads, replies, mentions or insights.

### threads_content_publish

> From EzBizi's composer the owner writes a post — usually the same short
> update going to their other connected accounts — and publishes or schedules
> it. EzBizi creates a Threads container on their connected profile and
> publishes it. Their own profile, their own words, one deliberate action per
> post.

### whatsapp_business_messaging

> EzBizi sends WhatsApp messages for businesses using EzBizi: approved template
> notifications that a business sets up in EzBizi's Automations (for example,
> telling a manager "You have received an alert: missed call"), and replies to
> people who messaged the business's WhatsApp number, inside the 24-hour window.
> For A-1 Chimney, a staff WhatsApp line answers technicians' questions from the
> company's own reference library (codes, branch contact details). Incoming
> messages and delivery statuses are received through the webhook and shown in
> EzBizi.

### whatsapp_business_management

> EzBizi reads the business's approved message templates
> (GET /{waba-id}/message_templates) so that when someone builds an automation
> with a "Send WhatsApp" step, they pick from their real approved templates and
> fill in its variables, instead of typing a template name that may not exist.

### Business Description (WhatsApp)

> EzBizi LLC makes EzBizi, a field-service CRM for home service companies
> (chimney, HVAC, plumbing): jobs, customers, scheduling, leads and marketing in
> one app. WhatsApp is used for customer and staff notifications and replies.

---

## 3. Reviewer instructions

> Sign in at https://app.ezbizi.com with the test account below (organization
> "EzBizi Demo", invented data; no second factor). Connections: Marketing ›
> Social Media › Connect. Messages from Facebook and Instagram appear under
> Leads. WhatsApp automations: Automations › New › Send WhatsApp.
>
> Email: meta@ezbizi.com
> Password: (Jose pastes it from Credentials/App Store Review Accounts.txt)

---

## 4. Data handling (EzBizi's answers)

- **Data processors / service providers with access to Platform Data:** Yes —
  Amazon Web Services (hosting, storage and encrypted backups, United States).
- **Responsible entity:** EZBIZI LLC, 800 SE 4th Ave Ste 107, Hallandale Beach,
  FL 33009, United States.
- **National-security requests in the last 12 months:** None.
- **Policies for public-authority requests:** requests are reviewed for
  legality, challenged where unlawful, and only the minimum data disclosed
  (tick the matching boxes) — **Jose to confirm.**

---

## 5. Screencasts

Each starts at EzBizi sign-in and shows the Meta login/consent screen (Meta
rejects recordings that cut it). Six videos cover every permission:

1. **Facebook** — connect (consent shows the five Page permissions), pick the
   Page, publish a post, "View post" opens it on Facebook.
2. **Messenger** — message the Page from another account, it appears in Leads,
   reply from EzBizi, reply shows in Messenger.
3. **Instagram** — connect, publish a photo, "View post" opens it.
4. **Instagram messages** — DM the account, appears in Leads, reply.
5. **Threads** — connect, publish, "View post" opens it.
6. **WhatsApp** — Automations › Send WhatsApp picks a real template; the message
   arrives on a phone; an inbound message appears in EzBizi.
