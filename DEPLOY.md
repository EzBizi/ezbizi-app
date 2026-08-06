# Deploying EzBizi

## The short version

```bash
git push production main
```

That's it. The hook on the app box does the rest.

## What actually happens

`git push production main` → `/var/repos/ezbizi.git` → `hooks/post-receive`:

1. Check out `main` into a **new timestamped release directory**
2. Copy `.env` from the live app (it never lives in the repo)
3. Symlink `storage/` to the shared one (uploads and logs survive releases)
4. `composer install --no-dev --optimize-autoloader`
5. `php artisan migrate --force`
6. Build config/route/view caches
7. **Boot the app as a health check**
8. Only then repoint `/var/www/ezbizi-current` at the new release
9. Prune all but the last 5 releases

**Any failure before step 8 aborts and leaves the running app untouched.** The
release directory is deleted and the push reports the error.

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

`storage/` and `bootstrap/cache/` are `ubuntu:www-data`, mode **2775**.

The setgid bit matters. `ubuntu` deploys, `www-data` serves. Without setgid, a
log file created by one is unwritable by the other, and the failure surfaces as
a **500 with no log entry** — because the thing that failed *was* the logging.
That cost me a confusing ten minutes; the bit is there so it doesn't cost anyone
else more.

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
