# EzBizi — development environment

**Phase 0 started 2026-08-05.** What exists, where, and how to work with it.

## What's running

| | |
|---|---|
| **Dev URL** | **https://dev.ezbizi.com** — Laravel 13.24.0, HTTP 200 |
| Framework | Laravel **13.24.0** |
| PHP | **8.4.24** (FPM socket `/run/php/php8.4-fpm.sock`) |
| Database | MySQL 8.0, schema **`ezbizi`**, base migrations applied |
| Server path | `/var/www/ezbizi` (web root `public/`) |
| nginx vhost | `/etc/nginx/sites-available/dev.ezbizi.com.conf` |
| SSL | wildcard `ezbizi.com-0002` (`*.ezbizi.com`), auto-renewing |

## Repository

**`H:\Development\EzBizi`** — deliberately **outside `H:\AWS`**, so no credential file or `.pem` can ever
be swept into a `git add`. First commit `0520deb`, 56 files. Copied here from `H:\EzBizi` on 2026-08-19; the original remains.

The product documentation moved *into* this repo on 2026-08-17 and now lives in `docs/`.
`H:\AWS\Application\` is the **server's** folder — its credentials and its key. `H:\AWS\_secrets\`
holds keys and credentials and is never a repo.

> **That move is exactly the hazard the separation was designed against**, and it caught this
> file: the credentials table below carried a live database password, and moving the file into
> a repo that is pushed to production put it one `git push` from the box. It was found and
> removed before the commit left this machine. **A document entering this repo is a document
> being published — scan it for secrets first.**

## Credentials

| | |
|---|---|
| DB name | `ezbizi` |
| DB user | `ezbizi`@`127.0.0.1` and `@localhost` |
| DB password | **`H:\AWS\_secrets\`** — not here, and not in any file inside this repo |
| `APP_KEY` | generated on the server, in `/var/www/ezbizi/.env` — **not in git** |

> The password was written out in this table until 2026-08-17, when this document moved into
> the repo and a plaintext credential became something a deploy would publish. **Rotate it** —
> it has been in a working tree, in chat transcripts, and in whatever has read this file.

## Verified working 2026-08-05

```
https://dev.ezbizi.com/            200   Laravel welcome, title "EzBizi"
/.env                              404   blocked
/storage/logs/laravel.log          404   blocked
/composer.json                     404   blocked
/artisan                           404   blocked
/vendor/autoload.php               404   blocked
/.git/config                       404   blocked
X-Powered-By                       absent (version not leaked)
X-Frame-Options                    SAMEORIGIN
```

Unaffected by the work: `www.ezbizi.com` 200 · `app.ezbizi.com` 200 · `srv.ezbizi.com` 200 ·
BIND still authoritative · MySQL, Webmin, named all active.

## Gotchas hit (both would recur)

**1. `.env` at `chmod 600` gives HTTP 500.** PHP-FPM runs as `www-data`; mode 600 means only
`ubuntu` can read it, so Laravel sees no `APP_KEY` and dies with *"No application encryption
key has been specified"* — a misleading error, since the key is present and correct.
**Use `640` with group `www-data`.** Still not world-readable, but the web server can read it.

**2. nginx 1.24 rejects the standalone `http2 on;` directive** — that's 1.25.1+. On 1.24 use
`listen 443 ssl http2;`. `nginx -t` catches it, which is exactly why the config is always
tested before reload.

**3. A pipeline ending in `head` always exits 0**, so `... | head -3 && LEAK=1` fires even with
zero matches. My first secret-scan reported a false positive because of it. Check
`grep -q` separately from displaying results. The same trap bit again on 2026-08-17 in the
other direction: a secret scan piped to `head -12` stopped before reaching the one file that
had a credential in it, and reported clean. **Never truncate the output of a scan you intend
to trust.**

**4. This machine cannot run four things the deploy gate can**, so a green local run is not a
green gate. Three are missing libraries; one was fixed.

| | Symptom | State |
|---|---|---|
| MySQL | `Access denied for user 'root'@'localhost'` on ~1100 tests | **fixed 2026-08-18** — MySQL 8.4.9 Community, schema `ezbizi_testing`. `php artisan test` talks to it. The Windows service could not be installed without elevation, so `mysqld` is a user process (`C:\ProgramData\MySQL\Data`). Start it with `mysqld --datadir=C:\ProgramData\MySQL\Data` if port 3306 is refused. |
| OpenSSL | `openssl_pkey_export(): Cannot get key from parameter 1` | **fixed 2026-08-17** — see below |
| GD | `GD extension is not installed` — image tests | **fixed 2026-08-19** — `extension=gd` in this machine's `php.ini`. Not a repo file. |
| dompdf | `Class "Dompdf\Dompdf" not found` — PDF tests | **fixed 2026-08-19** — `composer.phar install` pulled `dompdf/dompdf` from the lockfile; it had been missing from `vendor/`. |
| Windows paths | `ErrorAuditTest::test_a_file_scope_closure_does_not_carry_the_deploy_root_into_the_trace` — the trace comes back with `tests\Fixtures\…` backslashes and the assertion wants the forward-slash form | **Windows only** |
| Local TLS | `ImapReplySenderTest::test_a_credential_containing_a_line_break_is_refused` — the connection to the test server on `localhost` fails on the certificate *before* the credential is validated, so the refusal names the certificate rather than the line break | **Windows only** |

**A full local run is no longer the nine-red of 2026-08-19 morning.** GD and Dompdf are on this machine now (`HrOnboardingDocumentPdfTest` 13/13). Two Windows-only rows remain (path separators in a stack trace, localhost TLS). The deploy gate does **not** run here — `scripts/deploy.sh` pipes `git archive main` to `/opt/ezbizi-test` on the box. A green local run is still the weaker signal.

Run the full suite with `php -d memory_limit=1G vendor/bin/phpunit`. Plain `php artisan test` dies with a 128M exhaustion partway through — the parent's `-d` does not reach the processes it spawns.

The private customer-file backup has a disposable, database-free proof that uses the same
archive creator and restore verifier as the nightly scripts:

```bash
bash -n deploy/backup.sh
bash -n deploy/restore-check.sh
python3 deploy/tests/private-files-backup-restore-fixture.py
```

It creates only synthetic bytes under a fresh temporary directory. A green local fixture is
the Task 0 development gate, not the production durability verdict; the latter remains the
off-instance, production-sized Task 10A check after a normal deployment.

**The OpenSSL fix.** Windows PHP shipped no `openssl.cnf` and `OPENSSL_CONF` was unset, so
`openssl_pkey_new()` failed for *any* key — which meant every test that mints a keypair
(the Google Workspace service-account tests) errored before testing anything. PHP ships a
config it was not pointed at:

```powershell
[Environment]::SetEnvironmentVariable('OPENSSL_CONF',
  "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\extras\ssl\openssl.cnf",
  'User')
```

Set for this machine's user on 2026-08-17. A new shell picks it up; an already-open one needs
restarting. Verify with `php -r 'var_dump(openssl_pkey_new([]) !== false);'`.

A sqlite `:memory:` run is still not the gate (StartUp.md §8). This machine now has MySQL;
use `php artisan test` with no sqlite overrides. GD and Dompdf are present as of 2026-08-19.

## Permissions model

```
/var/www/ezbizi        ubuntu:www-data   dirs 755, files 644
  storage/             775  ← www-data writable
  bootstrap/cache/     775  ← www-data writable
  .env                 640  ← www-data readable, not world
```

Only those two paths are writable by the web server. Nothing else needs to be.

## Working on it

Local is the source of truth (plan §11). Edit in `H:\Development\EzBizi`, deploy to the server.

```bash
# on the server
cd /var/www/ezbizi
php8.4 artisan migrate
php8.4 artisan config:clear
```

**Always `php8.4`, never bare `php`** — that still resolves to 8.2, which Laravel 13 refuses.

## Phase 0 remaining

| | Status |
|---|---|
| PHP 8.4 upgrade | ✅ done |
| Laravel 13 skeleton | ✅ done |
| Database + migrations | ✅ done |
| dev vhost + SSL | ✅ done |
| Local repo, secrets excluded | ✅ done |
| Remove unused PHP 8.2 packages | ⬜ nothing uses them; safe to purge |
| GitHub remote | ⬜ name/owner undecided |
| CI pipeline | ⬜ |
| Automated backups (this box) | ✅ encrypted, off-instance, restore-checked nightly; the versioned private-file manifest is locally proven and awaits the Task 10A production-sized off-instance proof |
| Staging/production split (`app.ezbizi.com`) | ⬜ dev only so far |
| BIND secondary on the Websites box | ⬜ per README — removes the single-nameserver weakness |
