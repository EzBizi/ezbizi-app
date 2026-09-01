# Meta App Review — what to submit, and the words to submit it with

App: **EzBizi** (`1750581562857402`), Business `1378090931119436`
Written 2026-09-01, from the scopes the code actually requests.

---

## 1. The submission asks for 13 permissions. The code uses 7.

The seven, and nothing else, are these — read straight out of
`FacebookOAuth::SCOPES`, `InstagramOAuth::SCOPES` and `ThreadsOAuth::SCOPES`:

| Permission | Where it is used |
|---|---|
| `pages_show_list` | `FacebookOAuth::identify()` — lists the Pages to connect |
| `pages_read_engagement` | required by Meta to hold a Page token |
| `pages_manage_posts` | `FacebookPublisher` — the post itself |
| `instagram_business_basic` | `InstagramOAuth::identify()` — names the account |
| `instagram_business_content_publish` | `InstagramPublisher` — **missing from the submission** |
| `threads_basic` | `ThreadsOAuth::identify()` — names the profile |
| `threads_content_publish` | `ThreadsPublisher` — **missing from the submission** |

`public_profile` is granted by default and does not need review.

### Remove these six — the app never asks for them

`instagram_business_manage_messages`, `instagram_manage_comments`,
`pages_messaging`, `pages_manage_metadata`, `whatsapp_business_messaging`,
`whatsapp_business_management`, `business_management`.

Asking for a permission the screencast cannot demonstrate is the ordinary way
these submissions get rejected — the reviewer has to see each one used.

The cleanest removal is at the **use case** level, since four of them come in
as a group: on **Use cases**, remove *Connect with customers through WhatsApp*
and *Engage with customers on Messenger from Meta* entirely. EzBizi uses
neither. Then drop the leftovers inside the Instagram and Pages use cases.

### Add these two

Both live under **Use cases → Customize → Permissions and features**:

- Access the Threads API → `threads_content_publish` → Add to App Review
- Manage messaging & content on Instagram → `instagram_business_content_publish`

**Without these two an approval is worth nothing**: every read scope would be
granted and the app still could not post anything.

---

## 2. The "Allowed usage" description for each

One box per permission. These are written to answer the three things Meta
actually asks — what it does, what the person gets, why the app cannot work
without it.

### pages_show_list

> EzBizi is business management software for home service companies (chimney
> sweeps, HVAC, plumbing). After a business owner connects their Facebook
> account, EzBizi calls /me/accounts to show them the list of Pages they
> manage, so they can choose which Page their company's posts should go to.
> Many of our customers manage more than one Page — a main company Page and
> Pages for separate branches — so we cannot assume which one they mean. The
> chosen Page's id and token are stored against that company's record. Without
> this permission there is no way to present that choice, and no way to know
> which Page to publish to.

### pages_read_engagement

> EzBizi requests this because Meta requires it alongside pages_manage_posts in
> order to hold and use a Page access token. EzBizi uses it only to confirm
> that the connected Page is still reachable and that the stored token is still
> valid, so the app can tell the business owner "this account needs
> reconnecting" before they schedule a post that would fail. EzBizi does not
> read or display followers, comments, messages, or engagement analytics.

### pages_manage_posts

> This is the core of the feature. A business owner writes one post in EzBizi's
> composer — for example a seasonal chimney safety reminder or a photo of
> finished work — chooses which of their connected accounts it goes to, and
> either publishes it immediately or schedules it. For Facebook, EzBizi publishes
> to the selected Page's feed (text), /photos (with an image), or /videos, using
> the Page token obtained when they connected. Publishing to their own Page, as
> themselves, is the entire purpose of the integration. EzBizi does not post to
> Pages the user has not explicitly connected, and never posts without an
> action the user took.

### instagram_business_basic

> After a business owner connects their Instagram professional account through
> Instagram Login, EzBizi calls /me to read the account id, username and name.
> The username is shown in the app so the owner can see which account is
> connected and confirm it is the right one — several of our customers have both
> a personal and a business Instagram — and the id is what EzBizi publishes
> against. EzBizi does not read the account's media, followers or insights.

### instagram_business_content_publish

> This is what lets the business owner actually post. From the same composer
> they use for every other network, they attach a photo or a video and publish
> or schedule it; EzBizi creates a media container on their connected Instagram
> professional account and then publishes it. It is their own account, their own
> content, and their own explicit action every time — there is no automated or
> bulk posting. Instagram requires a photo or video, so EzBizi refuses a
> text-only post to Instagram before it is ever sent, and tells the person why.

### threads_basic

> After a business owner connects their Threads profile, EzBizi reads the
> profile id and username so the app can show which profile is connected and
> know which profile to publish to. EzBizi does not read their threads, replies,
> mentions or insights.

### threads_content_publish

> This is what lets the business owner actually post to Threads. From EzBizi's
> composer they write a post — usually the same short update they are sending to
> their other connected accounts — and publish or schedule it. EzBizi creates a
> Threads container on their connected profile and publishes it. Their own
> profile, their own words, one deliberate action per post.

---

## 3. What each screencast has to show

Meta rejects screencasts that skip the login. Each one needs, in order:

1. **The OAuth flow itself** — signing in to Meta and granting the permission,
   on screen, not cut out.
2. The permission being used to do the thing described above.
3. The result visible on the actual Facebook Page / Instagram account / Threads
   profile.

One recording per permission is allowed to be the same video re-uploaded, as
long as the permission being reviewed is visibly exercised in it. Practically
that is three recordings:

- **Facebook** — connect, pick a Page from the list (covers `pages_show_list`),
  compose, publish, show the post on the Page (`pages_manage_posts`).
- **Instagram** — connect, show the connected username
  (`instagram_business_basic`), compose with a photo, publish, show it on the
  profile (`instagram_business_content_publish`).
- **Threads** — connect, show the connected profile (`threads_basic`), compose,
  publish, show it on the profile (`threads_content_publish`).

`pages_read_engagement` is covered by the Facebook recording, since it is what
lets the connection be validated in it.

---

## 4. Before recording anything

**The publishers have to be live.** Facebook, Instagram and Threads publishing
were committed on 2026-09-01 and are not on app.ezbizi.com until the deploy desk
ships them. Recording against production before then would show the composer
refusing to post, which is the opposite of the demonstration.
