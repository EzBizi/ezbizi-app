# Deploying EzBizi

## The short version

```bash
scripts/release.sh --gates
```

This orchestrates web, Windows, Android, iOS and macOS, deploying API/web first. `client/VERSION` is the version source; the orchestrator normally commits a bump. Use `--no-bump` only when intentionally releasing the version already chosen.

For API/web alone use `scripts/deploy.sh --gates`; add `--code` to omit Flutter web. Gates are **off by default**, per the standing release policy. `--gates` runs them; `--skip-gates` skips them. Build success does not imply test success. A bare production push bypasses the local gate and does not publish a matching UI.

The orchestrator passes its exact commit as `--sha <full-sha>` to `deploy.sh`, which rejects disagreement with `EZBIZI_RELEASE_SHA`. Standalone deployment defaults to the commit at `main`. Archives, client builds and the version stamp use that pinned tree, not uncommitted edits.

`deploy.sh` stages the pinned `post-receive` and `receive-release` helpers and optional UI in unique directories. Its receive wrapper holds `/var/repos/ezbizi-deploy.lock` across hook installation, API activation and UI swap. The hook archives the received commit into a unique release directory and writes `.ezbizi-release-sha`; the wrapper checks API/UI identity. A failed UI swap restores the previous UI and reports failure. API activation is not automatically rolled back. A same-SHA retry may replay a failed hook only while production's ref still names that SHA.

The separate `/opt/ezbizi-test/.deploy-gate.lock` serializes sandbox tests. It is not the production deployment lock. Normal script deployments install the pinned helpers automatically; editing only the versioned hook without running deployment does not change the live hook.

nginx vhosts are **not** swapped by the hook. After a change under `deploy/nginx-*.conf`:

```bash
sudo cp deploy/nginx-security-headers.conf /etc/nginx/snippets/ezbizi-security-headers.conf
sudo cp deploy/nginx-app.ezbizi.com.conf /etc/nginx/sites-available/app.ezbizi.com.conf
sudo cp deploy/nginx-dev.ezbizi.com.conf /etc/nginx/sites-available/dev.ezbizi.com.conf
sudo cp deploy/nginx-wildcard-subdomains.conf /etc/nginx/conf.d/wildcard_subdomains.conf
sudo nginx -t && sudo systemctl reload nginx
```

`www.ezbizi.com` is served by `/etc/nginx/conf.d/wildcard_subdomains.conf` (root `/var/www/www`). `sites-available/ezbizi.conf` is not enabled. HSTS `includeSubDomains` only covers children of the host that sent the header, so the apex/www response is the one that protects every name.

Production `.env` should have `EZBIZI_SIGNUP_ENABLED=false` until mail can verify addresses. The app box also needs that value, not only phpunit. *(Measured 2026-11-13: the box `.env` has `EZBIZI_SIGNUP_ENABLED=true`. The condition above was met on 2026-09-14: self-signed-up accounts must verify their mailbox before writing (`c69ccc9d0`, `RequireVerifiedEmail`), and SES left the sandbox that day. Who flipped the value is not recorded here.)*

## What actually happens

The deploy script pushes its pinned SHA to production `main` through the receive wrapper, invoking `/var/repos/ezbizi.git/hooks/post-receive`:

1. Archive the exact received commit into a **new unique release directory** and record its SHA
2. Copy `.env` from the live app (it never lives in the repo) and set it to
   `ubuntu:www-data` mode `0640` — see [The `.env` trap](#the-env-trap)
3. Symlink `storage/` to the shared one (uploads and logs survive releases)
4. `composer install --no-dev --optimize-autoloader`
5. **`mysqldump` the live database into the release directory** — §11.2's
   "backup immediately before every migration"
6. `php artisan migrate --force`
7. `php artisan ezbizi:sync-permissions`
8. Build config/route/view caches
9. **Boot the app as a health check, as `www-data`** — the user that serves
10. Only then repoint `/var/www/ezbizi-current` at the new release, reload
    `php8.4-fpm` and `artisan queue:restart` so the workers pick it up
11. Prune all but the last 5 releases

### What "aborted" actually means

A failure at steps 1–4 aborts and **leaves the running app untouched**, in full:
the release directory is deleted and nothing outside it ever changed.

**From step 6 on, that is no longer true, and the hook no longer claims it is.**
`migrate --force` runs against the *production* database. If it fails halfway,
the code is untouched and the schema is not — whichever migrations ran are still
applied. An earlier version of the hook printed *"migrations failed — deployment
aborted, live app untouched"* and then deleted the release directory, which made
the message look true by removing the evidence.

So on any failure from step 6 onward the hook **keeps the release directory**,
because step 5's dump is inside it, and prints the restore command:

```bash
# release dirs are <stamp>-<sha12>-<random>; the hook prints the exact paths
gunzip -c /var/www/ezbizi-releases/<release-dir>/pre-migration-<stamp>.sql.gz | sudo mysql ezbizi
rm -rf /var/www/ezbizi-releases/<release-dir>    # once you have restored
```

**`migrate:rollback` is not a way back.** Several `down()` methods are
destructive — one drops every enrolled TOTP column — so rolling back destroys
data the failed migration never touched. Restore the dump.

The receive wrapper and hook serialize production deployment. Direct pushes still bypass local gates and coordinated UI upload; use the scripts for normal releases. The wrapper's identity checks are separate from application health checks.

## Why releases rather than pulling in place

A `git pull` into the live directory leaves the app half-updated while composer
runs, and a failed install leaves it broken with no way back. Here the live app
keeps serving the previous release until the new one is fully built and proven
to boot.

Rollback is repointing the symlink:

```bash
ls -1dt /var/www/ezbizi-releases/*/     # newest first
ln -sfn /var/www/ezbizi-releases/<release-dir> /var/www/ezbizi-current
sudo systemctl reload php8.4-fpm
```

## Connecting mail providers

Account work in Google's console, not deploy work — so it lives on its own in
**[MAIL-SETUP.md](MAIL-SETUP.md)**, along with what IMAP needs (nothing) and the
two refusals that look like bugs and are not.

Read it before setting `GOOGLE_MAIL_*` in `.env`. One decision in there —
Internal versus External on the consent screen — is the difference between
working this afternoon and waiting weeks for a Google security assessment.

## Shipping the installed apps

`scripts/deploy.sh` ships the web client, the API, and a content-guarded refresh
of the **app-box** peer watchdog (script + units, only if they are already
installed and md5 differs) — and, by the same rule, `ezbizi-backup.sh`,
`ezbizi-restore-check.sh` and the transcribe, label and staff-worker units. The refresh runs **after** the release is live and
cannot abort the deploy: a failed copy warns and leaves the box on the older
script. It does not first-install those units, does not touch the
`peer-url.conf` drop-in, and does not update `watch-peer-app.sh` on the
websites box. `scripts/release.sh` orchestrates Windows, Android, iOS and macOS using the build/signing helpers. Artifacts go under the main checkout's `Distribution/`. Build success is separate from store review and availability; account/submission work lives in **[STORES.md](STORES.md)**.

Read it before submissions; preserve registered application identifiers and signing identities. Do not raise minimum installed-client versions as part of a release; read [active constraints](ACTIVE-CONSTRAINTS.md).

## Where things are

| | |
|---|---|
| Bare repo | `/var/repos/ezbizi.git` |
| Releases | `/var/www/ezbizi-releases/<stamp>-<sha12>-<random>/` |
| Live symlink | `/var/www/ezbizi-current` → current release |
| Shared storage | `/var/www/ezbizi/storage` |
| Test sandbox | `/opt/ezbizi-test` — deliberately outside `/var/www`, with no `.env` |
| Environment | `/var/www/ezbizi/.env` — **on the box only, never committed** |
| Customers | https://app.ezbizi.com |
| Control plane | https://dev.ezbizi.com |

Both hostnames serve the **same** Flutter bundle and the same Laravel release. The
client reads its own hostname and picks a face (`client/lib/core/deployment.dart`);
`app.ezbizi.com` additionally returns 404 for `/api/v1/staff`, so the control plane is
separated at the transport layer and not only in the UI.

nginx config for both lives in `deploy/` and is versioned. To change either:

```bash
scp deploy/nginx-dev.ezbizi.com.conf app:/tmp/ && ssh app 'sudo cp /tmp/nginx-dev.ezbizi.com.conf /etc/nginx/sites-available/dev.ezbizi.com.conf && sudo nginx -t && sudo systemctl reload nginx'
```

## The test sandbox

`/opt/ezbizi-test` — outside the webroot, and outside `/var/www` entirely.

`scripts/deploy.sh --gates` extracts `git archive <pinned-sha>` there and runs the suite against
`ezbizi_testing`; without `--gates` it does not touch the sandbox.
`scripts/preflight-backend.sh [sha] [filter]` runs the same suite under the same lock
and publishes nothing. The sandbox has its own `storage/`, its own `.env.testing`, and **no `.env` at
all**. The extract takes `flock` on `/opt/ezbizi-test/.deploy-gate.lock` first:
two overlapping deploys used to `rm -rf bootstrap` while the other was still in
phpunit, and the first then failed 200+ tests with `Failed opening required
bootstrap/app.php`. The test database is shared, so they cannot run in parallel
anyway. The lock waits up to an hour.

That last part is the point of the whole arrangement. It used to live in
`/var/www/ezbizi` alongside the production `.env`, and Laravel falls back to `.env` when
the environment file it was asked for does not exist — which is how
`artisan db:wipe --env=testing` wiped production on 2026-08-06. Two guards were added
that day (the suite refuses any database whose name lacks `testing`, and `.env.testing`
now exists), but both are guards against a reachable mistake. Removing the `.env` removes
the reach:

```bash
cd /opt/ezbizi-test && php8.4 artisan tinker --execute="echo config('database.connections.mysql.database');"
# laravel        <- the framework default. Not `ezbizi`. There is nothing to fall back to.
```

`/var/www/ezbizi` is now shared state only — `.env`, `storage/`, `public-app/` — which is
what the releases symlink to and what nginx serves the Flutter bundle from.

## Permissions, and the trap in them

### The hook needs passwordless sudo

**`ubuntu` must have `NOPASSWD` sudo for the deploy to work.** It is true on this
box today (`(ALL) NOPASSWD: ALL`) and it was never written down, which is the
problem — this file is the rebuild document, and a rebuilt box or a tightened
`sudoers` now breaks the deploy at the second step with no stated cause.

The hook uses it four times, and each one is load-bearing rather than
convenient: `chown`/`chmod` to hand `.env` to the serving group, `mysqldump` for
the pre-migration snapshot, and `-u www-data` to run the boot check as the user
that serves. Before this change there was exactly one use, and it was written to
tolerate failure (`|| true`). These cannot be.

If sudo is ever narrowed, narrow it to those four commands rather than removing
it — a deploy that cannot snapshot the database before migrating it is worse
than no deploy.

### Ownership

`storage/` and `bootstrap/cache/` are `ubuntu:www-data`, mode **2775**.

*(Measured 2026-11-13: shared `/var/www/ezbizi/storage` is `ubuntu:www-data 0777`, no
setgid, and each release's `bootstrap/cache` is `ubuntu:ubuntu 0775` — see trap 2 below.)*

The setgid bit matters. `ubuntu` deploys, `www-data` serves. Without setgid, a
log file created by one is unwritable by the other, and the failure surfaces as
a **500 with no log entry** — because the thing that failed *was* the logging.
That cost me a confusing ten minutes; the bit is there so it doesn't cost anyone
else more.

### Changing `.env` between deploys — two traps, both silent

Adding a credential to the live `.env` does **not** reach the running app. Found
the hard way on 2026-08-26 while wiring the EZ Leads Google clients.

**1. The release has its own copy.** `/var/www/ezbizi/.env` is the master, and
step 2 of the hook *copies* it into each timestamped release. Editing the master
changes what the NEXT deploy gets and nothing about what is serving now:

```bash
# after editing /var/www/ezbizi/.env, push it into the live release too
ssh app 'REL=$(readlink -f /var/www/ezbizi-current)
         sudo cp -p /var/www/ezbizi/.env "$REL/.env"
         sudo chown ubuntu:www-data "$REL/.env" && sudo chmod 0640 "$REL/.env"'
```

**2. `config:cache` runs as `ubuntu`, not `www-data`.** `bootstrap/cache` is
`ubuntu:ubuntu`, so `sudo -u www-data php artisan config:cache` fails with
*Permission denied* — and if its output is piped anywhere, it fails looking like
it did nothing. The hook builds caches as `ubuntu` and only *health-checks* as
`www-data`; do the same by hand.

```bash
ssh app 'cd /var/www/ezbizi-current && php artisan config:cache'   # as ubuntu
```

Together these are worse than either alone: the master file is right, the
release file is stale, the cache build silently fails, and every check that
reads `config()` keeps returning null with nothing on screen explaining why.
Verify with the thing that actually matters rather than with `env()` — which is
always null once config is cached:

```bash
ssh app 'cd /var/www/ezbizi-current && sudo -u www-data php artisan tinker   --execute="var_dump(config(\"services.google_ads.client_id\") !== null);"'
```

Or just redeploy, which does all of it in the right order.

### The `.env` trap

**`/var/www/ezbizi/.env` must be `ubuntu:www-data`, mode `0640`. This is
required, not tidiness.**

```bash
ssh app 'sudo chown ubuntu:www-data /var/www/ezbizi/.env && sudo chmod 0640 /var/www/ezbizi/.env'

# and the check that actually answers the question — ask the serving user:
ssh app 'sudo -u www-data test -r /var/www/ezbizi/.env && echo readable || echo NOT READABLE'
```

It was `ubuntu:ubuntu 0640`, and php-fpm's pools are `user = www-data`. The user
that serves the app could not read the app's environment file. `ls -l` looks
entirely reasonable until you notice the group.

It did not show up because **Laravel skips env loading completely when
`bootstrap/cache/config.php` exists**, and the deploy builds that cache every
time. The site worked on a file it could not read. What that means in practice:

- `php artisan config:clear` on the box takes **both hostnames to a hard 500**,
  instantly, with no deploy having happened.
- Any deploy where `config:cache` fails ships a release that 500s on the first
  request. (That step is now guarded — it was the one step in the hook with no
  `if !` around it.)

**No boot check can catch this, however it is run.** That is worth stating
plainly, because the obvious fix looks like it works and does not: the health
check runs *after* `config:cache`, and Laravel's `LoadEnvironmentVariables`
returns immediately when a cached config exists — so it never opens `.env`,
whoever executes it. Running it as `www-data` is still right, and it now proves
the serving user can traverse the release, read `vendor/` and boot the
framework. It does not prove, and cannot prove, that `www-data` can read `.env`.

So the hook asserts that **directly**, as `www-data`, at the moment `.env` is
placed — before any cache exists to hide it. It also sets the release copy to
`ubuntu:www-data 0640` on every deploy, because `cp` carries the source's mode
across and the release inherited the bug.

An earlier draft of this section said the gate had been fixed by running it as
`www-data`. It had not; that claim was tested on the box and passes with the
fault present. It is left recorded here rather than quietly corrected, because a
deployment document that has once described a control it does not have is worth
being suspicious of twice.

`0640` and not wider: `APP_KEY` is in that file, and `APP_KEY` is what makes
every TOTP secret in the database readable.

## TLS certificates, and the reload nobody was doing

Certificates come from certbot over DNS-01 (`dns-rfc2136`, against our own
`named`). **One certificate covers both `app.ezbizi.com` and `dev.ezbizi.com`**,
so anything that happens to it happens to both hostnames at the same moment.

nginx reads its certificates **once, at master-process start**, and holds them in
memory for the life of that process. Renewing the file on disk therefore changes
nothing about what visitors are served. Something has to reload nginx.

Nothing did. There was no `renew_hook` in any `/etc/letsencrypt/renewal/*.conf`,
and the only deploy hook on the box was `webmin.sh`, which restarts webmin. The
certificate expires **3 November 2026**; certbot would have renewed it quietly
around **4 October** and both hostnames would have hard-failed together on the
3rd — thirty days after the renewal that was supposed to prevent it, with a green
certbot log sitting beside the outage.

*(Measured 2026-11-13: the served certificate is the `ezbizi.com-0002` lineage,
`*.ezbizi.com` + `ezbizi.com`, notAfter 2 Jan 2027 — it renewed and was reloaded;
`nginx-reload.sh` is installed and executable.)*

`deploy/letsencrypt-deploy-hook.sh` is that missing reload. Install it:

```bash
scp deploy/letsencrypt-deploy-hook.sh app:/tmp/ && ssh app \
  'sudo install -o root -g root -m 0755 /tmp/letsencrypt-deploy-hook.sh \
     /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh && rm -f /tmp/letsencrypt-deploy-hook.sh'
```

Anything executable in `renewal-hooks/deploy/` runs after **every** successful
renewal of **every** lineage — no per-certificate wiring, and nothing to forget
the next time a certificate is added. **The executable bit is what makes it
run**; certbot silently ignores a hook without it, which is why the backup now
archives `/etc/letsencrypt/renewal-hooks` (tar preserves the bit) alongside
`/etc/letsencrypt/renewal`.

Verify it — safe, and it does not spend a rate limit against real issuance:

```bash
ssh app 'sudo certbot renew --dry-run --run-deploy-hooks'
```

`--run-deploy-hooks` is needed: a plain `--dry-run` skips deploy hooks entirely,
so without it you are testing renewal and not the thing that was broken. Confirm
nginx actually reloaded — the **master PID stays the same** and the **workers are
new**, which is what distinguishes a reload from a restart:

```bash
ssh app 'ps -o pid,lstart,cmd -C nginx | head -3; journalctl -t letsencrypt-nginx-reload -n 5 --no-pager'
```

The hook reloads rather than restarts, and no-ops if nginx is not running. A
`restart` drops every in-flight request, and if the config on disk has drifted
into something invalid it stops nginx and then fails to start it — a renewal that
takes the site down instead of keeping it up.

The Health screen reports days-to-expiry as read **off the wire**, not off the
disk, for the same reason: `/etc/letsencrypt` and "what nginx is serving" are two
different facts, and only the second one is a customer's problem.

`GET /up` is Laravel's health probe. It is **readiness**: nginx, PHP-FPM and
MySQL (`DiagnosingHealth` runs `select 1`). It is **not** backups, mail or TLS.
Those stay on Development → Health. The websites box curls `/up`; the app box
curls `https://panel.triplexhost.net` (not `www.ezbizi.com` — that name is this
box). Units: `deploy/ezbizi-watch-peer.*`. `EZBIZI_PEER_URL` has no repo default.

### Peer watchdog install (TODO #23) — INSTALLED 2026-08-18 19:49 UTC

**Installed and running on both boxes.** Kept below for reference and reinstall.

**`www.ezbizi.com` is NOT the websites box.** It resolves to 100.24.188.241 — the
app box itself. Pointing the app-box watchdog at it would have been the app
checking whether the app is alive, which proves nothing and is the exact
anti-pattern this phase exists to avoid. The real peer target is
`https://panel.triplexhost.net` (32.194.222.151), set as a systemd drop-in at
`/etc/systemd/system/ezbizi-watch-peer.service.d/peer-url.conf` so a redeploy
cannot restore the self-check URL. No `ezbizi-alive.txt` was needed; that box
already answers on its own hostname.

`EZBIZI_PEER_URL=https://panel.triplexhost.net` is in the box `.env` (added
19:53 UTC, `.env.bak-20260818-peerurl` beside it) and the config cache was
rebuilt, so the Health screen names the host it actually watches. Verified as
`www-data`: `{"watching":"panel.triplexhost.net","verdict":{"ok":true,...}}`.

`peer-status.json` is mode 600 owned by `www-data`. Reading it as `ubuntu`
returns null — that is the file permission, not a broken watchdog. Check it with
`sudo`, or as `www-data`.

The scripts and units are in the release. **First install** is still this paste —
they are not in `/etc/systemd/system` until someone runs it. Do not run it without
Mia granting a window on **both** boxes. After that, `scripts/deploy.sh` recopies
`watch-peer-websites.sh` and the three app-box units (plus the other files listed
under [Shipping the installed apps](#shipping-the-installed-apps)) from the live release onto
`/usr/local/bin` and `/etc/systemd/system` **only when md5 differs**, after both
halves of the release, and a failed copy does not abort the deploy. A missing
dest is skipped, so a box that never had the watchdog does not get it from a
normal deploy. The `peer-url.conf` drop-in is local config and is never
overwritten. `watch-peer-app.sh` on the websites box stays a snapshot — that box
is not this SSH.

Checked not-installed 18 Aug 18:05 UTC (no units, no `peer-status.json`,
`www.ezbizi.com/ezbizi-alive.txt` was 404). Installed 18 Aug 19:49 UTC. Box
script matched repo 18 Aug 20:05 UTC after a manual copy (deploy.sh did not yet
refresh `/usr/local/bin`).

**App box** (`ssh app`). Paths assume the live symlink `/var/www/ezbizi-current`
(or `/var/www/ezbizi` if that is still the current name — `readlink` first):

```bash
ssh app 'set -euo pipefail
ROOT=$(readlink -f /var/www/ezbizi-current 2>/dev/null || readlink -f /var/www/ezbizi)
sudo install -m 0755 "$ROOT/deploy/watch-peer-websites.sh" /usr/local/bin/ezbizi-watch-peer-websites.sh
sudo install -m 0644 "$ROOT/deploy/ezbizi-watch-peer.service" /etc/systemd/system/ezbizi-watch-peer.service
sudo install -m 0644 "$ROOT/deploy/ezbizi-watch-peer.timer" /etc/systemd/system/ezbizi-watch-peer.timer
sudo install -m 0644 "$ROOT/deploy/ezbizi-watch-peer-failed.service" /etc/systemd/system/ezbizi-watch-peer-failed.service
for u in php8.4-fpm nginx mysql named; do
  sudo mkdir -p /etc/systemd/system/${u}.service.d
  printf "[Unit]\nOnFailure=ezbizi-watch-peer-failed.service\n" | sudo tee /etc/systemd/system/${u}.service.d/ezbizi-peer.conf >/dev/null
done
sudo systemctl daemon-reload
sudo systemctl enable --now ezbizi-watch-peer.timer
systemctl is-active ezbizi-watch-peer.timer
ls -l /var/www/ezbizi/storage/app/peer-status.json || true
'
```

**Websites box** (`32.194.222.151`). No alive file: that box already answers
on `https://panel.triplexhost.net`. Copy `watch-peer-app.sh` from the app repo
onto that box; it is **not** deployed there by `scripts/deploy.sh`, and a later
deploy will not refresh it. Drift there is still a manual md5 check. Verify with
`curl -fsS https://app.ezbizi.com/up` from the websites box and
`curl -fsS https://panel.triplexhost.net` from the app box.

```bash
sudo install -m 0755 /path/to/ezbizi/deploy/watch-peer-app.sh /usr/local/bin/ezbizi-watch-peer-app.sh
sudo tee /etc/systemd/system/ezbizi-watch-peer-app.service >/dev/null <<EOF
[Unit]
Description=Ask whether the EzBizi app box is answering /up

[Service]
Type=oneshot
ExecStart=/usr/local/bin/ezbizi-watch-peer-app.sh
EOF
sudo tee /etc/systemd/system/ezbizi-watch-peer-app.timer >/dev/null <<EOF
[Unit]
Description=Watch the EzBizi app box every five minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
Unit=ezbizi-watch-peer-app.service

[Install]
WantedBy=timers.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now ezbizi-watch-peer-app.timer
```

## What runs the clock

systemd units, all versioned in `deploy/` and installed on the app box (installed set measured 2026-11-13):

| Unit | Does |
|---|---|
| `ezbizi-scheduler-0/1/2.timer` → `ezbizi-scheduler@N.service` | `artisan schedule:run` every minute, from three timers on staggered minutes so a slow run never skips the next one (since 2026-10-06) |
| `ezbizi-scheduler-slow.timer` → `ezbizi-scheduler-slow.service` | `artisan schedule:run`, the slow lane |
| `ezbizi-worker.service` | `artisan queue:work`, restarted on deploy |
| `ezbizi-worker-long.service` | `queue:work database-long --queue=long` (jobs over 90 s) |
| `ezbizi-worker-staff.service` | `queue:work --queue=staff` (staff questions) |
| `ezbizi-transcribe.timer`, `ezbizi-label.timer` | call transcription; call labels and speaker split |
| `ezbizi-backup.timer` | nightly `ezbizi-backup.sh` then `ezbizi-restore-check.sh` |

**Neither existed until 2026-08-06**, which meant audit retention, impersonation
expiry and the automation outbox had never run once — and nothing anywhere reported
an error, because nothing was broken. They simply never executed. If automation
ever looks inert again, check these first:

```bash
ssh app 'systemctl is-active ezbizi-scheduler-0.timer ezbizi-scheduler-1.timer ezbizi-scheduler-2.timer ezbizi-scheduler-slow.timer ezbizi-worker ezbizi-worker-long ezbizi-worker-staff'
```

## Rules

**Edit locally, deploy through the scripts. Never edit on the server as shipping.**

Server-side edits are for diagnosis only. Editing there means the repo and the
running app disagree, everything passes on the box, and the next clean deploy
ships something broken. That drift is what the plan blames for A1 Tools (§17),
and it took eleven commits to reproduce it here.

To check the two are in step:

```bash
# on the box
cat /var/www/ezbizi-current/.ezbizi-release-sha
# locally
git show --no-patch <pinned-release-sha>
```

## Not yet done

- **No mandatory CI gate.** `scripts/deploy.sh --gates` checks the pinned backend tree in `/opt/ezbizi-test` and the client. Gates remain opt-in; a bare push bypasses them.
- **No staging.** `dev.ezbizi.com` is the production control plane, not a test environment.
- **No automatic rollback.** The hook refuses to ship a broken build, but if a
  bad release passes its health check, rolling back is the manual step above.
