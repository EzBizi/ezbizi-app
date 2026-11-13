# EzBizi

EzBizi is a multi-tenant field-service CRM with a Laravel API and Flutter clients for web, Windows, Android, iOS and macOS. This is a clean rewrite: A1 Tools may be read for business context, but its code is never copied here.

Start with [StartUp.md](StartUp.md), then the [documentation index](docs/README.md). [STATUS](docs/EZBIZI-STATUS.md) records current state; [TODO](docs/EZBIZI-TODO.md) tracks remaining product and operational work.

| Directory | Purpose |
|---|---|
| `app/`, `routes/`, `database/` | Laravel API, domain logic, jobs and schema |
| `client/` | Flutter application and tests |
| `www/` | Former static marketing site. **www.ezbizi.com is WordPress since 2026-11-02: editing `www/` publishes nothing** (only `/download/*` and `/videos/*` are still served from `/var/www/www`); change site pages with wp-cli |
| `resources/` | Mail templates, website snippet and HR source material |
| `deploy/`, `scripts/` | Infrastructure and release tooling |
| `tests/` | Backend and release regressions |

Use PHP 8.4 and MySQL; see [local setup](docs/EZBIZI-DEV-ENVIRONMENT.md). `composer setup` prepares the configured local backend: inspect the resolved database first. `composer dev` runs the API; queue workers and Flutter run separately as described in [development rules](docs/DEVELOPMENT-RULES.md). There is no root npm/Vite build: nginx serves Flutter at the production root.

[Deployment](docs/DEPLOY.md) covers release scripts, optional gates and failure handling. [Release notes](docs/RELEASE-NOTES.md) distinguish implemented changes from deployed releases. The client version comes from `client/VERSION`, not Flutter's template `pubspec.yaml` version.
