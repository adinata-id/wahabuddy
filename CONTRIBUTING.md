# Contributing to WahaBuddy

Thanks for helping! WahaBuddy is a single-admin, self-hosted tool — keep the
scope small and the behavior honest.

## Ground rules

- **No exactly-once claims.** WAHA sends are non-idempotent HTTP POSTs. Time
  outs and crashes are `unknown`, resolution is an explicit human decision,
  and the docs/UI must never promise otherwise.
- **No secrets in code, logs, fixtures, or screenshots.** Never commit
  `.env`, API keys, private hosts, or real contact data.
- **No invented WAHA capabilities.** Unsupported/unknown stays surfaced as
  such; never simulate success.
- Tests fake all WAHA traffic. Never send live requests from tests or CI.

## Development setup

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan storage:link
php artisan wahabuddy:admin
```

MySQL 8 is the default database; the migrations stay portable.

## Before opening a PR

```bash
composer types:check   # PHPStan level 7 must pass
vendor/bin/pint        # auto-format; commit the result
npm run typecheck && npm run build
php artisan test       # all suites green, including tests/Integration
```

- Domain behavior changes need a failing test first.
- Keep the send pipeline invariants intact: atomic claims, token-guarded
  completion, pacing under the session gate, and revision holds.
- Document deliberate deviations in `docs/implementation-context.md`.

## Reporting issues

Include the sanitized error from the story/broadcast detail page (error codes
never contain secrets), your WAHA version/edition if known, and whether the
session was `WORKING` at the time.
