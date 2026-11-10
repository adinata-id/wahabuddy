# WahaBuddy Implementation Context

Record of tooling, versions, and decisions made while implementing the plan in
`docs/wahabuddy-spec.md`. Updated per task. No secrets or personal data belong
in this file.

## Bootstrap (Task 1) — 2026-10-31

### Commands actually run

```bash
composer global require laravel/installer            # installer v5.32
laravel new wahabuddy --react --database=sqlite --phpunit --boost --git --force --no-interaction
lerd link wahabuddy                                   # site http://wahabuddy.test
lerd db:create                                        # created wahabuddy + wahabuddy_testing (MySQL)
lerd artisan migrate --force
lerd artisan storage:link
lerd artisan wayfinder:generate --with-form
npm ci && npm run build && npm run typecheck
```

### Versions actually installed

| Component            | Version                                                                                         |
| -------------------- | ----------------------------------------------------------------------------------------------- |
| PHP                  | 8.5.11 (host + lerd `php85-fpm`)                                                                |
| Laravel framework    | 13.39.0                                                                                         |
| Inertia (PHP)        | inertiajs/inertia-laravel 3.6.0                                                                 |
| React / TypeScript   | React 19.2, TS 5.7 (starter kit)                                                                |
| Tailwind CSS         | 4.x via `@tailwindcss/vite`                                                                     |
| PHPUnit              | 12.5.42                                                                                         |
| Laravel Boost        | 2.10.4 (dev)                                                                                    |
| Laravel Wayfinder    | 0.1.23 (route/action TypeScript generation)                                                     |
| Node / npm           | v22.23.4 / 10.9.9                                                                               |
| Frontend bundler     | `vite` (aliased to `@voidzero-dev/vite-plus-core`), commands `vp build` / `vp dev` / `vp check` |
| lerd                 | 1.37.0 (local dev: nginx + php-fpm 8.5 + MySQL + Mailpit, site `wahabuddy.test`)                |
| MySQL (lerd service) | 8.x container `lerd-mysql`, published on host `127.0.0.1:3306`                                  |

### Local development environment (lerd)

The user requested lerd for local development. Conventions applied:

- Site linked as `wahabuddy.test` (PHP 8.5 FPM, Node 22).
- Artisan/tests run through `lerd artisan …` so `lerd-mysql` resolves inside the
  site container. Host-side `php artisan` also works for non-DB commands.
- Mail captures to lerd Mailpit (`lerd-mailpit:1025`).

### Deviations from the spec (with reasons)

1. **Database: MySQL instead of SQLite default** — user decision on
   2026-10-31 ("untuk databasenya gunakan mysql saja jangan sqlite"). `.env`,
   `.env.example`, and `phpunit.xml` all default to MySQL. Migrations remain
   portable so SQLite still works where needed; CI (Task 9) runs MySQL. The
   concurrency integration tests (Task 5/7) will run against MySQL with a
   shared database — the spec's "two processes + shared SQLite file" mode is
   replaced by the spec-sanctioned "run also on MySQL" mode.
2. **Vite tooling** — the current React starter kit ships `vp` (vite-plus)
   scripts: `npm run build`/`dev`/`check`/`types:check`. Added the documented
   alias `npm run typecheck` (`tsc --noEmit`) as required by the plan; no
   bundler replacement.
3. **Wayfinder** — starter kit generates typed route/action helpers. Must
   regenerate with `php artisan wayfinder:generate --with-form` after route
   changes (plain generate drops `.form()` helpers used by starter pages).
   Generated `resources/js/routes` + `resources/js/actions` are gitignored.

### Tooling obligations (spec section A) status

- **Laravel Boost MCP**: installed via `--boost` flag (Boost 2.10.4, dev
  dependency). `php artisan boost:mcp` verified responding to an MCP
  `initialize` handshake over stdio on 2026-10-31. The `laravel-boost` Claude
  Code plugin launches that command from the project directory; its first
  connection attempt failed before the app existed and retries automatically.
  Boost skills enabled: `laravel-best-practices`, `testing-best-practices`,
  `fortify-development`, `inertia-react-development`, `tailwindcss-development`,
  `wayfinder-development`, `infer-conventions`, `deploying-to-cloud`.
  Guidelines are injected into `AGENTS.md` (gitignored, per Boost default).
- **Context7 MCP**: used during bootstrap — resolved `/laravel/docs`
  (`__branch__13.x`) and queried the React starter-kit installation docs.
  Will be used again for WAHA/Laravel API details in Tasks 3–8.
- **Boost tools usage**: `search-docs`/`database-query`/`database-schema` will
  be used through the MCP connection once the plugin reconnects; the project
  rules from Boost guidelines (Pint `--dirty`, PHPUnit via `make:test --phpunit`,
  Inertia v3 conventions) are being followed regardless.

### Notes

- Registration disabled by removing `Features::registration()` (Fortify 1.41);
  `/register` returns 404, welcome/login no longer link to it, and the register
  page component was removed. Starter `RegistrationTest` self-skips via
  `skipUnlessFortifyHas`.
- Admin bootstrap: `php artisan wahabuddy:admin` (hidden password prompt, no
  default password, re-run never silently resets; explicit `--reset-password`
  exists).
- Known npm bug (npm/cli#4828) hit once: vite-plus native optional deps
  missing after installer run — fixed with `rm -rf node_modules && npm ci`
  (lockfile untouched).

## Task 9 — packaging, CI, deployment (2026-10-31)

### PHP baseline deviation

The committed `composer.lock` resolves symfony/http-foundation v8.1.10, which
requires PHP >= 8.4.1 — so the practical minimum is **PHP 8.4** (not 8.3 as
the spec's soft target allowed). Dockerfile uses `php:8.4-*-alpine`, CI runs
the matrix on 8.4 + 8.5, and README/deployment docs state the requirement.

### Docker build notes

- `resources/js/routes`, `resources/js/actions`, and `resources/js/wayfinder`
  are gitignored build artifacts. A dedicated `routes` stage installs
  composer deps and runs `wayfinder:generate --with-form`; the node stage
  installs Alpine's php84 (plus the Laravel extension set) because the vite
  wayfinder plugin itself re-runs `php artisan wayfinder:generate` during
  `npm run build`.

### Verified (podman compose, project `wahabuddy-ci`, torn down afterwards)

- `podman compose build` succeeds end-to-end
- `up -d`: db → init (migrations) → app healthy → worker/scheduler/web
- `GET /up` → 200; login page renders WahaBuddy
- `php artisan wahabuddy:admin` works inside the container
- scheduler executes `waha:send-stories` every minute (schedule:work)
- full stack restart: data persists (admin account intact), health returns

### CI

`.github/workflows/ci.yml`: frontend job (typecheck + `vp check` + build
with npm cache) and a PHP matrix (8.4/8.5) on a MySQL 8.4 service —
PHPStan, Pint --test, and `php artisan test` including the two-process
integration suites. No live WAHA traffic anywhere.

## Task 10 — final acceptance (2026-10-31)

### Definition of Done review

- Clean-clone quick start via README (MySQL + local storage — user override of
  the spec's SQLite default; R2/Redis optional and absent) — verified against
  the Docker path end-to-end; native path follows the same commands.
- Admin creation safe (`wahabuddy:admin`), public registration 404s — tested.
- Settings change URL/key/session; secrets encrypted at rest, never in props;
  revision changes hold old work — tested (SettingsTest, StorySchedulerTest,
  BroadcastTest).
- Text/image/video stories: created, scheduled, and sent through the WAHA
  adapter — all proven with faked HTTP (exact endpoints/payloads asserted).
  **Live WAHA integration remains unverified**: no WAHA target or test
  recipient was authorized in this session. Nothing here claims otherwise.
- Concurrency: two-process integration proofs on shared MySQL for both story
  and broadcast claims (exactly one winner; only the token owner completes).
- 3-attempt cap, 60/300s + Retry-After backoff, expiry boundary, UTC
  persistence + display timezone, stale→unknown recovery, orphan media
  cleanup, 10k log retention — all under test.
- Broadcast v1: real text/image sending pipeline with per-recipient jobs,
  session-shared pacing (8–20s random), 50-attempt batch pauses,
  pause/resume/cancel, unknown-pauses-campaign, sweeper recovery — tested.
- Capabilities surface as supported/unsupported/unknown; nothing simulated.
- CI matrix (PHP 8.4/8.5 on MySQL), PHPStan/Pint/tsc/build green; Docker
  Compose boot + admin + scheduler tick + restart persistence verified via
  podman and torn down.
- Repository audited: no secrets, no private hosts (the spec's research
  reference instance is redacted to a placeholder), no env/backup files tracked.

### Commands run for the final gate

- `php artisan test` → 124 passed, 2 skipped (starter registration tests,
  disabled by design) — 781 assertions
- `composer types:check` (PHPStan level 7) → 0 errors
- `vendor/bin/pint --test` → pass
- `npm run typecheck` → clean; `npm run build` → success
- `docker compose config -q` → valid; `podman compose build/up/restart` → OK
- Browser smoke (lerd + Playwright): login, dashboard, story create,
  broadcast wizard, settings incl. connection save + JSON test endpoint

### Known limitations (honest)

1. Live WAHA send/delete/sync never executed — no target/recipient was
   authorized. Adapter fidelity relies on the documented contract (D2) plus
   sanitized fixtures; a first live smoke against a disposable session is the
   recommended next step.
2. SQLite is untested by decision (user chose MySQL); migrations stay
   portable but CI covers MySQL only.
3. The laravel-boost MCP plugin never reconnected inside the implementing
   session (cached failure); `php artisan boost:mcp` itself answers handshakes
   and `claude mcp list` shows it connected. Context7 was used; Boost skills
   were configured at install time.
