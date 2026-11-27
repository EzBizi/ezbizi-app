# EzBizi — development environment

**Phase 0 started 2026-08-05.** What exists, where, and how to work with it.

## What's running

| | |
|---|---|
| **Dev URL** | **https://dev.ezbizi.com** — Laravel 13.24.0, HTTP 200 |
| Framework | Laravel **13.24.0** |
| PHP | **8.4.27** as measured 2026-11-13 (FPM socket `/run/php/php8.4-fpm.sock`) |
| Database | MySQL 8.0, schema **`ezbizi`**, base migrations applied |
| Server path | `/var/www/ezbizi-current` → the live release (Laravel `public/`); `/var/www/ezbizi` holds only shared `.env`, `storage/` and the Flutter bundle `public-app/` — see [DEPLOY](DEPLOY.md#where-things-are) |
| nginx vhost | `/etc/nginx/sites-available/dev.ezbizi.com.conf` |
| SSL | wildcard `ezbizi.com-0002` (`*.ezbizi.com`), auto-renewing |

## Repository

**`H:\Development\EzBizi`** — deliberately **outside** the credentials folder, so no credential file or `.pem` can ever
be swept into a `git add`. First commit `0520deb`, 56 files. Copied here from `H:\EzBizi` on 2026-08-19; the original remains.

The product documentation moved *into* this repo on 2026-08-17 and now lives in `docs/`.
`H:\Development\AWS\Application\` is the **server's** folder. Keys and passwords live in
`H:\Development\Credentials\AWS\` and are never a repo.

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
| DB password | **`H:\Development\Credentials\AWS\`** — not here, and not in any file inside this repo |
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
| MySQL | `Access denied for user 'root'@'localhost'` on ~1100 tests | **fixed 2026-08-18** — MySQL 8.4.9 Community, schema `ezbizi_testing`. `php artisan test` talks to it. The Windows service could not be installed without elevation, so `mysqld` is a user process (`C:\ProgramData\MySQL\Data`). Start it with `mysqld --datadir=C:\ProgramData\MySQL\Data` if port 3306 is refused. *(Since 2026-08-31 the Startup-folder script `EzBizi-start-mysql.cmd` starts it at logon and checks first; check for a running `mysqld` before starting another.)* |
| OpenSSL | `openssl_pkey_export(): Cannot get key from parameter 1` | **fixed 2026-08-17** — see below |
| GD | `GD extension is not installed` — image tests | **fixed 2026-08-19** — `extension=gd` in this machine's `php.ini`. Not a repo file. |
| dompdf | `Class "Dompdf\Dompdf" not found` — PDF tests | **fixed 2026-08-19** — `composer.phar install` pulled `dompdf/dompdf` from the lockfile; it had been missing from `vendor/`. |
| phpspreadsheet | `Class "PhpOffice\PhpSpreadsheet\Spreadsheet" not found` — six XLSX transfer tests | **fixed 2026-08-28** — same shape as the dompdf row: in `composer.json` and the lockfile, absent from this machine's `vendor/`. `composer.phar install` pulled it. |
| Windows paths | `ErrorAuditTest::test_a_file_scope_closure_does_not_carry_the_deploy_root_into_the_trace` — the trace comes back with `tests\Fixtures\…` backslashes and the assertion wants the forward-slash form | **Windows only** |
| Local TLS | **Thirteen red, not one** (count it, do not trust this number: it grows with every test added to those two files) — every `ImapTransportTest` and `ImapReplySenderTest` case that opens the fake IMAP/SMTP server. The connection to `localhost` fails on the certificate *before* anything the test is about, so each one reports the certificate instead (e.g. `test_a_credential_containing_a_line_break_is_refused` names the certificate rather than the line break). Confirmed 2026-08-28. | **Windows only** |

**A full local run is no longer the nine-red of 2026-08-19 morning.** GD and Dompdf are on this machine now (`HrOnboardingDocumentPdfTest` 13/13). Two Windows-only rows remain (path separators in a stack trace, localhost TLS). The deploy gate does **not** run here — `scripts/deploy.sh --gates` (or `scripts/preflight-backend.sh`) pipes `git archive <pinned-sha>` to `/opt/ezbizi-test` on the box. A green local run is still the weaker signal.

**It runs the other way too: this machine accepts a migration a stock MySQL refuses.**
A migration that creates a trigger through `DB::unprepared` is the case. A `CHECK` constraint
cannot say "this column may not change", so a trigger is the only mechanism for that, and
there is nothing wrong with writing one. But MySQL refuses `CREATE TRIGGER` without `SUPER` while
binary logging is on (error 1419), and the application's MySQL user correctly does not have
`SUPER`. Local `mysqld` here runs as a user process with different settings, so it never bit,
and the migration passed every local run while blocking every deploy for two days.

The server fix is `log_bin_trust_function_creators=ON`, persisted so it survives a restart —
applied to the box on 2026-08-28. **Any fresh environment with binlog on will hit this**, and
the failure is confusing rather than obvious: the migration dies partway, leaves tables and
columns behind unrecorded in `migrations`, and every later attempt
fails somewhere downstream instead — 1050 table exists, then 1215 cannot add foreign key, then
1060 duplicate column. Three symptoms, one cause. If you meet those, look for orphaned tables
from a half-applied migration before believing any of them.

The sandbox at `/opt/ezbizi-test` uses the **same MySQL instance as production**, so it could not
create those triggers either. Every gate run before 2026-08-28 was hitting 1419 the moment it
reached this migration. The gate was not slow so much as doomed — the constraint blocked
production *and* blocked the gate that existed to catch it blocking production. The SSH keepalive
added the same day is a real fix for a real problem, but on its own it only bought a doomed run
more time to fail in.

Run the full suite with `php -d memory_limit=1G vendor/bin/phpunit`. Plain `php artisan test` dies with a 128M exhaustion partway through — the parent's `-d` does not reach the processes it spawns.

### Never run tests against the shared `ezbizi_testing` database

Every session shares one working tree and one MySQL. `RefreshDatabase` drops and rebuilds the
whole schema, so two concurrent runs destroy each other mid-run and neither can tell — the
failures land in suites you never touched.

The tell is an impossible pair: `Table 'ezbizi_testing.migrations' doesn't exist` in one test
and `Table 'users' already exists` in the next. Also seen as "table definition has changed",
"prepared statement needs re-prepared", and unknown-column errors for columns that plainly
exist. It reads exactly like a half-failed `migrate:fresh` — which is what everybody diagnoses
first, and it is not. Before believing that diagnosis, check whether anyone else is running.

Give yourself your own database and put it in front of phpunit as a real environment variable:

```bash
DB_DATABASE=ezbizi_testing_<yourmodule> php -d memory_limit=1G vendor/bin/phpunit
```

`phpunit.xml`'s `<env>` declarations carry no `force="true"`, so a real environment variable
wins. Create it once with `CREATE DATABASE ezbizi_testing_<yourmodule>`; nothing to commit and
nothing for anyone else to trip over.

Confirmed independently on six modules, each with its own numbers: Inventory 107 errors →
112/112. Email 18 errors → 11, and those 11 are the Windows TLS row above rather than anything
real. Fleet 65 errors → 35/35 and 22/22. Services 43 errors → 11/11. Marketing 10 errors →
32/32. Phone deadlocks and phantom missing tables → 150/150.

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

A sqlite `:memory:` run is still not the gate (§8 of the [former StartUp](history/2026-09-08-startup.md); [DEVELOPMENT-RULES](DEVELOPMENT-RULES.md)). This machine now has MySQL;
run with no sqlite overrides, using the `phpunit` command above for a full run. GD and Dompdf are present as of 2026-08-19.

## Permissions model

```
/var/www/ezbizi        ubuntu:www-data   dirs 755, files 644
  storage/             775  ← www-data writable
  bootstrap/cache/     775  ← www-data writable
  .env                 640  ← www-data readable, not world
```

Only those two paths are writable by the web server. Nothing else needs to be.

*(Phase 0 layout. Since releases, code lives in `/var/www/ezbizi-releases/*` and ownership is described, with 2026-11-13 measurements, in [DEPLOY › Ownership](DEPLOY.md#ownership).)*

## Working on it

> **Superseded:** `/var/www/ezbizi` no longer holds the code, and migrations run only
> through the deploy hook. Do not run the commands below; see [DEPLOY](DEPLOY.md). Kept
> as the Phase 0 record.

Local is the source of truth (plan §11). Edit in `H:\Development\EzBizi`, deploy to the server.

```bash
# on the server
cd /var/www/ezbizi
php8.4 artisan migrate
php8.4 artisan config:clear
```

**Always `php8.4`, never bare `php`** — that still resolves to 8.2, which Laravel 13 refuses. *(Measured 2026-11-13: bare `php` now resolves to 8.4 via `/etc/alternatives/php`; the scripts and units still call `php8.4` explicitly.)*

## Phase 0 remaining

| | Status |
|---|---|
| PHP 8.4 upgrade | ✅ done |
| Laravel 13 skeleton | ✅ done |
| Database + migrations | ✅ done |
| dev vhost + SSL | ✅ done |
| Local repo, secrets excluded | ✅ done |
| Remove unused PHP 8.2 packages | ⬜ nothing uses them; safe to purge |
| GitHub remote | ✅ `origin` = `github.com/EzBizi/ezbizi-app` |
| CI pipeline | ⬜ none (no `.github/workflows`); gates are `scripts/deploy.sh --gates`, opt-in |
| Automated backups (this box) | ✅ encrypted, off-instance, restore-checked nightly; the versioned private-file manifest is locally proven and awaits the Task 10A production-sized off-instance proof |
| Staging/production split (`app.ezbizi.com`) | ◐ `app.ezbizi.com` is production for customers and `dev.ezbizi.com` the production control plane; there is no staging ([DEPLOY](DEPLOY.md#not-yet-done)) |
| BIND secondary on the Websites box | ⬜ per README — removes the single-nameserver weakness (2026-11-13: `ns1` and `ns2` both still resolve to 100.24.188.241) |
