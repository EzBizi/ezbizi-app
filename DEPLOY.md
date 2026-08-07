# Deploying EzBizi

## The short version

```bash
git push production main
```

That's it. The hook on the app box does the rest.

## What actually happens

`git push production main` → `/var/repos/ezbizi.git` → `hooks/post-receive`:

1. Check out `main` into a **new timestamped release directory**
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
10. Only then repoint `/var/www/ezbizi-current` at the new release
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
gunzip -c /var/www/ezbizi-releases/<stamp>/pre-migration-<stamp>.sql.gz | sudo mysql ezbizi
rm -rf /var/www/ezbizi-releases/<stamp>    # once you have restored
```

**`migrate:rollback` is not a way back.** Several `down()` methods are
destructive — one drops every enrolled TOTP column — so rolling back destroys
data the failed migration never touched. Restore the dump.

> Still missing from §11.2: a **deployment lock**. Two pushes landing at once
> will still interleave. Not fixed here.

## Why releases rather than pulling in place

A `git pull` into the live directory leaves the app half-updated while composer
runs, and a failed install leaves it broken with no way back. Here the live app
keeps serving the previous release until the new one is fully built and proven
to boot.

Rollback is repointing the symlink:

```bash
ls -1dt /var/www/ezbizi-releases/*/     # newest first
ln -sfn /var/www/ezbizi-releases/<stamp> /var/www/ezbizi-current
sudo systemctl reload php8.4-fpm
```

## Where things are

| | |
|---|---|
| Bare repo | `/var/repos/ezbizi.git` |
| Releases | `/var/www/ezbizi-releases/<timestamp>/` |
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

`scripts/deploy.sh` extracts `git archive main` there and runs the suite against
`ezbizi_testing`. It has its own `storage/`, its own `.env.testing`, and **no `.env` at
all**.

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

The setgid bit matters. `ubuntu` deploys, `www-data` serves. Without setgid, a
log file created by one is unwritable by the other, and the failure surfaces as
a **500 with no log entry** — because the thing that failed *was* the logging.
That cost me a confusing ten minutes; the bit is there so it doesn't cost anyone
else more.

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

## What runs the clock

Two systemd units, both versioned in `deploy/` and installed on the app box:

| Unit | Does |
|---|---|
| `ezbizi-scheduler.timer` → `.service` | `artisan schedule:run` every minute |
| `ezbizi-worker.service` | `artisan queue:work`, restarted on deploy |

**Neither existed until 2026-08-06**, which meant audit retention, impersonation
expiry and the workflow outbox had never run once — and nothing anywhere reported
an error, because nothing was broken. They simply never executed. If automation
ever looks inert again, check these first:

```bash
ssh app 'systemctl is-active ezbizi-scheduler.timer ezbizi-worker'
```

## Rules

**Edit locally, push to deploy. Never edit on the server.**

Server-side edits are for diagnosis only. Editing there means the repo and the
running app disagree, everything passes on the box, and the next clean deploy
ships something broken. That drift is what the plan blames for A1 Tools (§17),
and it took eleven commits to reproduce it here.

To check the two are in step:

```bash
# on the box
cd /var/www/ezbizi-current && git --git-dir=/var/repos/ezbizi.git log --oneline -1
# locally
git log --oneline -1
```

## Not yet done

- **No CI.** `scripts/deploy.sh` runs the suite against `git archive main` in the
  sandbox at `/opt/ezbizi-test` before pushing, so the gate tests exactly what
  ships — but a bare `git push production main` still bypasses it entirely.
- **No staging.** `dev.ezbizi.com` exists and is the obvious home for one.
- **No automatic rollback.** The hook refuses to ship a broken build, but if a
  bad release passes its health check, rolling back is the manual step above.
