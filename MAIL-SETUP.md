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

Only two, and the consent screen will name them:

| Scope | Why |
|---|---|
| `gmail.readonly` | Listing, reading and searching a mailbox. Also covers `users.getProfile`, which is how EzBizi checks the account signed in with is the address being connected. |
| `gmail.send` | The reply path, and nothing else. |

Deliberately **not** requested, so nobody adds them thinking it will help:

- `https://mail.google.com/` — full account, including permanent deletion.
- `gmail.modify` — the tempting one-scope simplification. It permits labelling
  and binning. EzBizi marks nothing at Google: read state is its own
  `message_seen` table, precisely so that opening a shared inbox here does not
  mark a message read in Gmail for everybody else in the company.

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

## Still to come

**Push notification for `email.received`.** Gmail can tell EzBizi when mail
arrives, over Cloud Pub/Sub — a topic, a subscription, and `users.watch()`
renewed within seven days. It needs more console work in the same project.
Until then, new mail is noticed by polling.

**Microsoft 365.** Not built. It would be a third reader beside Gmail and IMAP,
and the mailbox model already carries the provider that would select it.
