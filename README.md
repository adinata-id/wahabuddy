# WahaBuddy

Self-hosted panel for scheduling **WhatsApp Status/Story** posts and running
**paced broadcasts** through your own [WAHA](https://waha.devlike.pro/)
instance.

One installation, one administrator, one active WAHA connection and session.
No SaaS, no billing, no public sign-up.

## Features (v1)

- **Story scheduler** — text, image, and video status posts with UTC
  persistence, display timezone (default Asia/Jakarta), expiry boundary,
  atomic worker claims, and a strict 3-attempt retry policy.
- **Ambiguity-safe sending** — timeouts, crashes, and 5xx responses after the
  request may have been processed become `unknown`, never auto-retried;
  resolution requires an audited admin decision. We never claim exactly-once.
- **Broadcasts** — `{name}`-templated text/image campaigns to opted-in
  contacts and groups with recipient tracking, 8–20 s random pacing shared
  across campaigns on the same session, batch pauses every 50 HTTP attempts,
  pause/resume/cancel, and unknown-outcome pausing.
- **Operational visibility** — dashboard counts per state, scheduler/worker
  heartbeats, append-only logs, capability reporting (supported/unsupported/
  unknown is never faked).
- **Portable config** — all WAHA settings live in the database behind the
  Settings page; secrets are stored encrypted and never sent back to the
  browser.

## Requirements

- PHP 8.4+ (the committed lockfile requires it) with `pdo_mysql`, `mbstring`, `xml`, `bcmath`, `intl`, `zip`
- Composer 2, Node 22+ (build only)
- MySQL 8+
- A running [WAHA](https://waha.devlike.pro/docs/how-to/installation/) with a
  paired session (WahaBuddy never creates or restarts sessions)
- S3-compatible storage (e.g. Cloudflare R2) for media: WAHA downloads
  image/video files itself, so uploads are served as presigned URLs and
  local-disk storage is not supported
- Optional: `ffprobe` for video codec detection

## Quick start (native)

```bash
git clone <your-fork> wahabuddy && cd wahabuddy
composer install --no-interaction
npm ci && npm run build

cp .env.example .env
php artisan key:generate --ansi     # FIRST INSTALLATION ONLY — keep APP_KEY stable
# edit .env: APP_URL, DB_*, AWS_* (S3-compatible bucket — required for media upload)

php artisan migrate --force
php artisan storage:link            # only serves media left over from the removed local-disk mode
php artisan wahabuddy:admin         # interactive; hidden password prompt

# queue worker (systemd/supervisor in production):
php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=180

# scheduler trigger (system cron, every minute):
* * * * * cd /path/to/wahabuddy && php artisan schedule:run >> /dev/null 2>&1
```

Log in, open **Settings**, point WahaBuddy at your WAHA base URL + API key,
pick the session, run a connection test — then schedule a story or sync
contacts and run a broadcast.

## Quick start (Docker Compose)

```bash
cp .env.example .env
# set APP_URL, DB_PASSWORD, APP_KEY (generate: docker run --rm wahabuddy-app php artisan key:generate --show)
docker compose up -d --build
docker compose run --rm init php artisan wahabuddy:admin
```

See **[docs/deployment.md](docs/deployment.md)** for both paths in detail,
including worker/cron setup, backups, updates, and restore.

## Tests

```bash
composer types:check        # PHPStan (level 7)
vendor/bin/pint --test      # PHP style
npm run typecheck           # TypeScript
npm run build               # frontend build
php artisan test            # unit + feature + integration (MySQL)
```

All WAHA traffic in the test suite is faked — no live sends, ever, from CI.

## Documentation

- [Deployment guide](docs/deployment.md) — native VPS + Docker Compose
- [Implementation context](docs/implementation-context.md) — versions,
  tooling, and deviations
- [Product specification](docs/wahabuddy-spec.md) — the full v1 spec
- [WAHA docs](https://waha.devlike.pro/docs/) — the upstream API

## License

[MIT](LICENSE). WahaBuddy connects to WAHA; it does not bundle WAHA and does
not require a WAHA Plus license for its own operation.
