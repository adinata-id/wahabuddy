# WahaBuddy Deployment

Two tested paths: a native VPS and Docker Compose. Both expect MySQL 8+ and a
running [WAHA](https://waha.devlike.pro/) whose session is already paired —
WahaBuddy picks an existing session and never creates or restarts one.

Shared rules that apply to both paths:

- **Keep `APP_KEY` stable across deploys.** WAHA credentials in the database
  are encrypted with it; losing the key makes the stored connection unusable.
  Back it up somewhere safe.
- One scheduler trigger only. Either a system cron running `schedule:run`, or
  `schedule:work` in a container — never both against the same database.
- Exactly one queue worker process group (`--tries=1 --timeout=180`). The
  180 s job timeout, 150 s HTTP timeout, 240 s queue `retry_after`, and 300 s
  session-lock lease are tuned together; change them only as a set.
- Production checklist: HTTPS in front, `APP_DEBUG=false`, webroot points at
  `public/`, `storage/` and `.env` are never web-readable, and the Boost MCP
  dev tooling stays out of the runtime.

---

## Path A — Native VPS

### Prerequisites (tested versions)

| Component  | Version                                                                                                                        |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------ |
| PHP        | 8.4+ (the committed lockfile requires PHP ≥ 8.4.1; tested on 8.4–8.5) with `pdo_mysql mbstring xml bcmath intl zip pcntl exif` |
| Composer   | 2.x                                                                                                                            |
| Node + npm | 22.x (build step only)                                                                                                         |
| MySQL      | 8.x                                                                                                                            |
| Cron       | any                                                                                                                            |

### Install

```bash
git clone <your-fork> /srv/wahabuddy
cd /srv/wahabuddy

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build

cp .env.example .env
php artisan key:generate --ansi      # FIRST INSTALLATION ONLY
$EDITOR .env                         # APP_URL, DB_*, optionally MEDIA_DISK/AWS_*
php artisan migrate --force
php artisan storage:link
php artisan wahabuddy:admin          # interactive admin creation (hidden password)

chown -R www-data:www-data storage bootstrap/cache
```

Media must be fetchable **from the WAHA container/host network**: set
`APP_URL` to an address WAHA can reach — `localhost` inside the WAHA
container is not your server. If media lives on S3/R2, fill the `AWS_*`
block, set `MEDIA_DISK=s3`, and use the **Test storage** button on the
Settings page (a backend fetch still doesn't prove WAHA can reach it).

### Deploy/update

```bash
cd /srv/wahabuddy
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan queue:restart
```

Never run a seeder that resets accounts; the only user source is
`wahabuddy:admin`.

### Worker (systemd)

`/etc/systemd/system/wahabuddy-worker.service`:

```ini
[Unit]
Description=WahaBuddy queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/srv/wahabuddy
ExecStart=/usr/bin/php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=180
Restart=always
RestartSec=3
KillSignal=SIGTERM
TimeoutStopSec=200

[Install]
WantedBy=multi-user.target
```

`systemctl enable --now wahabuddy-worker`. The stop grace must exceed the
180 s job timeout so an in-flight send can finish.

### Cron (single line)

```cron
* * * * * cd /srv/wahabuddy && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Run it as `www-data` (or the app user). The dispatcher self-serializes with
`withoutOverlapping`, but do not add a second, different scheduler.

---

## Path B — Docker Compose

Services: `web` (nginx), `app` (PHP-FPM), `worker`, `scheduler`
(`schedule:work` — no host cron needed), one-shot `init` (migrations +
storage link, must succeed before the others start), and `db` (MySQL 8.4).
`app`, `worker`, `scheduler`, and `init` share one image.

### Install

```bash
cp .env.example .env
$EDITOR .env    # set APP_URL, DB_PASSWORD; APP_KEY comes next

# Generate a stable APP_KEY once (keep the value in .env and your vault):
docker compose build
docker compose run --rm app php artisan key:generate --show

docker compose up -d --build           # init runs migrations automatically
docker compose run --rm app php artisan wahabuddy:admin
```

- Storage (`/var/www/html/storage`) and the MySQL data live in named
  volumes; `docker compose down` without `-v` keeps all data. **Never run
  `down -v` on a production install.**
- `APP_KEY` is provided via `.env` — containers never generate one, so
  restarts keep the database decryptable.
- Public media is served by nginx from the shared storage volume
  (read-only); vhost config lives in `docker/nginx.conf`.
- Health checks: `docker compose ps` (app/db) and `GET /up` through the web
  service. Set `WEB_PORT` to change the published port.

### Update

```bash
docker compose build
docker compose up -d
```

`init` re-runs migrations before the new workers serve.

### MySQL from outside Compose

Set `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` to your own
server and remove the `db` service (plus its `depends_on`). Existing SQLite
data is **not** auto-migrated — export/import with `mysqldump` if you ever
switched engines.

---

## Backup & restore

Back up three things, together:

1. **Database**: `mysqldump wahabuddy > wahabuddy.sql`
   (Docker: `docker compose exec db mysqldump -u<wahabuddy> -p wahabuddy`).
2. **Media**: the `storage/app/public/stories` tree (local disk) — or nothing
   extra when `MEDIA_DISK=s3`.
3. **`APP_KEY`** from `.env` — required to decrypt the stored WAHA
   credentials.

Restore: recreate `.env` (same `APP_KEY`), import the dump, restore the
storage tree, `php artisan storage:link`, restart the worker. Do not commit
backups into git.

## Operational notes

- **Heartbeats** — the dashboard shows the last scheduler run (from
  `scheduler_runs`) and a queue-worker heartbeat; a stale heartbeat banner
  prints the exact worker command to run.
- **Retention** — story logs are pruned weekly to the newest 10,000 rows;
  orphaned media uploads (>24 h, unreferenced) are removed weekly.
  `send_attempts` (the send evidence) is never pruned by these jobs.
- **Broadcast pacing** is enforced per session (stories and all campaigns
  share it): a random 8–20 s delay after each send plus a ≥60 s pause every
  50 HTTP attempts. This reduces risk; it guarantees nothing about account
  blocking.
