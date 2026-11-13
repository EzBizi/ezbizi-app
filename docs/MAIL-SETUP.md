# Connecting mail providers

Everything here is **account work in somebody else's console**, not code. The
code is built and deployed; these are the credentials it has nothing to do
without.

Nothing here is required for EzBizi to run. Until a provider is set up, the
Email module says so on screen in a sentence, and refuses to *start* a sign-in
rather than failing halfway through one. That is deliberate — see
`notReadyBecause()` on each reader.

---

## IMAP — nothing to set up

Works today. A customer enters their mail server, port and password on the
Email → Settings tab.

Two things worth knowing before somebody reports them as bugs:

- **A private mail server is refused, on purpose.** `RemoteHostGuard` resolves
  the host and rejects loopback, private and link-local addresses, because our
  server is inside a network and the host comes from a customer. A business
  whose mail server is only reachable inside their own office genuinely cannot
  be connected, and the refusal says so.
- **TLS is required.** A server that only speaks cleartext is refused rather
  than silently downgraded.

EzBizi does **not** use PHP's `imap` extension. It was removed from core in 8.4
and, more importantly, `imap_open()` re-resolves the hostname — which would
undo the guard above. The socket is opened directly against the address the
guard approved, with the certificate verified against the name the customer
typed. Do not "fix" a connection problem by installing `php8.4-imap`; it is not
used and would not be consulted.

---

## Google — five steps in the Google Cloud console

### 1. A project with the Gmail API enabled

Create a Google Cloud project (or reuse one) and enable the **Gmail API** on it.

### 2. Decide Internal or External — this is the expensive decision

On the OAuth **consent screen**:

- **Internal** — available only if every address being connected belongs to one
  Google Workspace organization. No verification, no review, works immediately.
  **If this fits, take it.**
- **External** — anything else. Both scopes below are *restricted*, so Google
  requires verification **and a third-party security assessment**. That is
  weeks of calendar time and a real cost, not a form.

For A-1 Chimney's own inboxes (`a-1chimney.com` and its regional sub-domains),
Internal fits. It stops fitting the moment an unrelated customer connects a
Google inbox of their own.

### 3. An OAuth 2.0 client

Type **Web application**. Register this redirect URI on it, **character for
character** — a mismatch is the most common failure and Google's error for it
is unhelpful:

```
https://app.ezbizi.com/api/v1/email/google/callback
```

### 4. The scopes it will ask for

The consent screen names these:

| Scope | Why |
|---|---|
| `gmail.readonly` | Listing, reading and searching a mailbox. Also covers `users.getProfile`. |
| `gmail.send` | The reply path, and nothing else. |
| `gmail.modify` | Two things, both label changes: move a thread to Trash from EzBizi (not permanent delete), and mark a thread read at Google when an inbox has been switched to do that. |

Deliberately **not** requested:

- `https://mail.google.com/` — full account, including permanent deletion.

**Opening a message in EzBizi does not mark it read in Gmail — unless that
inbox has been switched to do so.** Read state is EzBizi's own `message_seen`
table, recorded per person, which is what lets a shared inbox say "seen by
Jenny" rather than carrying one flag for everybody.

Email > Settings has a per-inbox switch, **off by default and off on every
inbox that has not chosen it**, which also removes Gmail's UNREAD label from
the conversation when somebody opens it here.

Leave it off on an inbox a team shares. Google's read state is ONE flag for the
whole mailbox, so turning it on there means one person opening a customer's
message hides it from a colleague who has not read it — the failure a shared
inbox exists to prevent. It is meant for the inbox one person reads, where "I
have read this" and "this mailbox has read this" are the same statement.

This sentence used to say, without qualification, that EzBizi never marks mail
read at Google. That was true until the switch shipped in 0.0.0151, and it is
the kind of claim somebody reads BEFORE handing over access to their mail — so
it is worth keeping honest rather than approximately right.

### 5. Two environment variables, then deploy

In the live `.env` on the app box (see `DEPLOY.md` — `.env` never lives in the
repo):

```
GOOGLE_MAIL_CLIENT_ID=....apps.googleusercontent.com
GOOGLE_MAIL_CLIENT_SECRET=...
```

A third is only needed if the public origin ever differs from `APP_URL`:

```
GOOGLE_MAIL_REDIRECT_URI=https://app.ezbizi.com/api/v1/email/google/callback
```

No code changes. The moment those exist, `notReadyBecause()` answers null, the
catalogue publishes `usable: true`, and the "Connect with Google" control
appears.

### Checking it worked

Email → Settings should stop saying Google is unavailable. Then connect one
address end to end. **Sign in with the wrong Google account on purpose** — the
callback should refuse, name both addresses, and revoke the grant back to
Google. That refusal is the check that stops somebody's private mail being read
through a shared inbox, and it is worth seeing work once.

---

## Google Workspace — domain-wide delegation

This is a **third** way to open a shared inbox, for companies that would rather
connect the domain once than sign in as each address. Per-inbox Google sign-in
and IMAP stay as they are.

A Workspace administrator signs in to prove they run the domain. EzBizi then
impersonates **only the addresses somebody picks from the Directory** (info@,
jobs@). Personal mailboxes are not imported. Google Groups are not in this
version.

### Preferred: Google Workspace Marketplace Admin install

Pasting scopes in Domain-wide delegation is the fallback. The path that does
not ask a Super Admin to paste URLs is a **Marketplace listing**: they open
the listing, **Admin install**, **Allow**. Google grants the four scopes as
part of that install.

The Marketplace SDK is enabled on Cloud project `ezbizi-506117`
(`development@ezbizi.com`). A **public** listing still needs Google's review
before a customer domain can install it. **Do not save visibility as Private**
— that setting cannot be changed later, and a private listing only works
inside ezbizi.com.

Console, signed in as `development@ezbizi.com`:

1. App configuration (Public, Admin only, Web app `https://app.ezbizi.com`, the four Gmail/Directory scopes):
   https://console.cloud.google.com/apis/api/appsmarket-component.googleapis.com/googleapps_sdk_oauth?project=ezbizi-506117
2. Store listing (name EzBizi, icons in `assets/marketplace/`, terms `https://www.ezbizi.com/terms/`, privacy `https://www.ezbizi.com/privacy/`, support `https://www.ezbizi.com/`):
   https://console.cloud.google.com/apis/api/appsmarket-component.googleapis.com/googleapps_sdk_publish?project=ezbizi-506117
3. OAuth consent must be **External** and include the same four scopes:
   https://console.cloud.google.com/auth/overview?project=ezbizi-506117

When the listing URL exists, put it on the box:

```
GOOGLE_MAIL_MARKETPLACE_URL=https://workspace.google.com/marketplace/app/...
```

Email → Settings then offers **Install EzBizi in Google**. Health still names
any scope that did not land.

### Fallback: paste the client in Domain-wide delegation

### What has to exist besides the OAuth client above

1. In the same Google Cloud project, create a **service account**. Enable the
   **Gmail API** and the **Admin SDK API**.
2. Open the service account and copy:
   - the client email (`…@….iam.gserviceaccount.com`)
   - a JSON key's **private key**
   - the numeric **Unique ID** (Client ID) — this is what Google Admin asks for,
     not the `….apps.googleusercontent.com` OAuth client id
3. In **Google Admin** (the customer's Admin Console, not Cloud Console):
   Security → Access and data control → API controls → Domain-wide delegation
   → Add new, paste that numeric client ID, and allow exactly these scopes:

```
https://www.googleapis.com/auth/gmail.readonly
https://www.googleapis.com/auth/gmail.send
https://www.googleapis.com/auth/gmail.modify
https://www.googleapis.com/auth/admin.directory.user.readonly
```

Do not add `mail.google.com` (permanent delete / admin). `gmail.modify` is Trash.

### Environment variables on the app box

```
GOOGLE_MAIL_WORKSPACE_CLIENT_EMAIL=ezbizi@….iam.gserviceaccount.com
GOOGLE_MAIL_WORKSPACE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----\n"
GOOGLE_MAIL_WORKSPACE_CLIENT_ID=123456789012345678901
```

The OAuth client (`GOOGLE_MAIL_CLIENT_ID` / `_SECRET`) is still required: it is
how a Workspace administrator proves they run the domain. The service account
is what opens listed mailboxes afterwards. Two keys, two jobs.

The domain is unique across EzBizi. The first organization that proves it runs
`a-1chimney.com` is the only one that may impersonate addresses there.

### Checking it worked

Email → Settings → Connect Workspace. Sign in as a Workspace administrator on
that domain. If Directory listing fails, the screen keeps the numeric client ID
and the three scopes — allow them in Google Admin, then try again. Then add a
shared inbox and pick it from the directory. **Do not pick a person's own
mailbox.**

---

## Amazon SES — platform mail (SMTP on 587)

This is EzBizi's own outbound mail (resets, invitations, Health-screen tests),
not a customer's inbox. Click-by-click, including the sandbox request and the
`.env` names, lives on **TODO #1** and the Mail console section of
`EZBIZI-TODO.md`. The transport is **plain SMTP** (`MAIL_MAILER=smtp`,
`MAIL_HOST=email-smtp.us-east-1.amazonaws.com`, port 587). Do not switch
production to `MAIL_MAILER=ses` — that path wants IAM keys and is what has
been sitting empty. **Checked on the box 18 Aug 18:05 UTC:** it was still
`MAIL_MAILER=ses` with `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`
present-and-empty, plus leftover `MAIL_HOST=127.0.0.1` and `MAIL_PORT=2525`.
Those two host/port lines do nothing under `ses` and will mislead the next
person who reads `.env`. Leave them out once SMTP is set.

**Done — measured on the box 2026-11-13:** `MAIL_MAILER=smtp`,
`MAIL_HOST=email-smtp.us-east-1.amazonaws.com`, `MAIL_PORT=587`. SES production
access was granted 2026-09-14 (50,000/day; sandbox gone — memory
`ses-production-access-appeal`), so the sandbox request is history.

SES home (us-east-1):
https://us-east-1.console.aws.amazon.com/ses/home?region=us-east-1#/homepage

---

## Microsoft 365 — per-inbox OAuth

Same shape as Gmail: PKCE, Graph list/open/search/reply, token refresh.
Click-by-click is **TODO #29**. Register an app in Azure
(https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade,
walkthrough https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app).
Redirect URI `https://app.ezbizi.com/api/v1/email/microsoft/callback` character
for character. Delegated scopes `Mail.Read` `Mail.Send` `offline_access`
`User.Read`. Env: `MICROSOFT_MAIL_CLIENT_ID`, `MICROSOFT_MAIL_CLIENT_SECRET`,
optional `MICROSOFT_MAIL_REDIRECT_URI`. Until those exist, Email refuses to
start a sign-in and says so. Shared team inboxes only.
*State 2026-11-13: no `MICROSOFT_MAIL_*` key is in the production `.env`, so
Microsoft 365 is still not set up. Google (`GOOGLE_MAIL_CLIENT_ID`/`_SECRET`)
and the Workspace service account (`GOOGLE_MAIL_WORKSPACE_*`) are set;
`GOOGLE_MAIL_MARKETPLACE_URL` is not.*

**Every value above was checked against the code on 2026-09-09** —
`MicrosoftOAuth::CALLBACK_PATH` and the four `SCOPE_` constants — rather than
trusted from this page. They match.

### The one choice on the form that this page did not name

**Supported account types must be MULTI-TENANT** — *"Accounts in any
organizational directory (Any Microsoft Entra ID tenant - Multitenant)"*.

Not the default. Azure's registration form defaults to single-tenant, which is
the right answer for an app your own staff sign into and the wrong one here: a
customer's Outlook mailbox lives in the CUSTOMER'S tenant, not ours. Pick
single-tenant and it will work perfectly when tested with a company address and
refuse every customer who is not us, with an error about the account not
existing in the directory — which reads like their problem and is ours.

The code is already committed to this and cannot be talked out of it:
`MicrosoftOAuth` authorises, exchanges and revokes against
`login.microsoftonline.com/common/...`, and `/common` is the multi-tenant
endpoint. A single-tenant registration and a `/common` endpoint disagree, and
the sign-in is where that disagreement surfaces.

Personal Microsoft accounts (the "and personal Microsoft accounts" variant) are
not needed. This module connects shared team inboxes on Microsoft 365, and
offering Outlook.com would widen the consent screen for a case the module does
not serve.

---

## Still to come

**Push notification for `email.received`.** Gmail can tell EzBizi when mail
arrives, over Cloud Pub/Sub — a topic, a subscription, and `users.watch()`
renewed within seven days. It needs more console work in the same project.
Until then, new mail is noticed by polling.

