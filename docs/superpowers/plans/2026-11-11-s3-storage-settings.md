# DB-Managed S3/R2 Storage Settings — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Configure, test, and enable/disable S3/R2 media storage entirely from the Settings page — credentials live encrypted in the database; env `AWS_*` only seeds the first row.

**Architecture:** A singleton `s3_connections` row (encrypted casts, mirror of `waha_connections`) is read per operation by a `MediaStorage` service that builds S3 adapters via `Storage::build()` behind a static per-process cache keyed by config hash — no global config mutation, no worker restarts. All existing storage call sites (`MediaController`, `MediaUrlResolver`, `SentMediaCleanup`, `PruneOrphanMedia`) migrate to this service; `S3Availability` is deleted. Disable semantics are per-row: only `disk='s3'` rows are blocked, legacy `disk='public'` rows keep previewing and purging.

**Tech Stack:** Laravel (PHP 8.5), Inertia + React, PHPUnit. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-11-11-s3-storage-settings-design.md` — read it before Task 1; the plan argues from it. Also read `.factory/skills/testing-best-practices/SKILL.md` before writing tests.

## Global Constraints

- Every task is strict TDD: write the failing test, watch it fail, minimal code, watch it pass, commit. Run the narrowest test set: `vendor/bin/phpunit <file> --filter <name>`.
- After any PHP edit in a task: `vendor/bin/pint --dirty --format agent` before committing.
- No new composer/npm dependencies. Media storage ops go through `App\Services\Media\MediaStorage` only.
- Key/secret values never appear in any HTTP response, Inertia prop, log line, or `last_test_error` (static messages only: `write_failed`, `fetch_failed`, `content_mismatch`).
- All new routes register inside the existing `Route::middleware(['auth', 'verified'])` group in `routes/web.php`; throttles exactly `10,1` (save, test) and `6,1` (clear secret).
- Throttled-endpoint tests follow the `advanceThrottleWindow()` convention (`$this->travel(61)->seconds()`) from `tests/Feature/StorageSettingsTest.php`.
- Commit messages end with `Co-Authored-By: Claude Code <noreply@anthropic.com>`.
- PHP style: constructor promotion, explicit return types, curly braces always, TitleCase enums, PHPDoc over inline comments (see AGENTS.md).

---

### Task 1: `s3_connections` table + `S3Connection` model

**Files:**

- Create: `database/migrations/2026_11_11_100000_create_s3_connections_table.php` (via `php artisan make:migration create_s3_connections_table --create=s3_connections --no-interaction`)
- Create: `app/Models/S3Connection.php`
- Modify: `phpunit.xml` (neutralize `AWS_*` — the developer's real `.env` carries live credentials, and `S3Connection::singleton()` seeds from them)
- Test: `tests/Feature/S3ConnectionTest.php` (via `php artisan make:test --phpunit S3ConnectionTest --no-interaction`)

**Interfaces:**

- Consumes: `config('filesystems.disks.s3')` (env `AWS_*` values), pattern `App\Models\WahaConnection`.
- Produces (used by Tasks 2, 3, 4, 5, 6, 7):
    - `S3Connection::singleton(): self`
    - `S3Connection::isConfigured(): bool`
    - `S3Connection::toDiskConfig(): array` (keys `driver,key,secret,bucket,region,endpoint,use_path_style_endpoint,throw`)
    - `S3Connection::configHash(): string`, `S3Connection::configHashFor(array $diskConfig): string`
    - properties `enabled,key,secret,bucket,region,endpoint,use_path_style_endpoint,last_test_at,last_test_status,last_test_error`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\S3Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class S3ConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_singleton_from_env_config_and_enables_when_complete(): void
    {
        config(['filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => 'env-key',
            'secret' => 'env-secret',
            'bucket' => 'env-bucket',
            'region' => null, // AWS_DEFAULT_REGION absent in many installs
            'endpoint' => 'https://acct.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true,
        ]]);

        $row = S3Connection::singleton();

        $this->assertTrue($row->enabled);
        $this->assertSame('env-key', $row->key);
        $this->assertSame('env-secret', $row->secret);
        $this->assertSame('env-bucket', $row->bucket);
        $this->assertSame('auto', $row->region, 'Empty region must seed as auto (R2).');
        $this->assertSame('https://acct.r2.cloudflarestorage.com', $row->endpoint);
        $this->assertTrue((bool) $row->use_path_style_endpoint);
    }

    public function test_seeds_disabled_when_env_credentials_incomplete(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3', 'key' => 'env-key']]);

        $row = S3Connection::singleton();

        $this->assertFalse($row->enabled);
        $this->assertFalse($row->isConfigured());
    }

    public function test_existing_row_is_never_reseeded_from_env(): void
    {
        $row = S3Connection::singleton();
        $row->forceFill(['key' => null, 'secret' => null, 'bucket' => null])->save();

        config(['filesystems.disks.s3.key' => 'later-env-key']);
        $row = S3Connection::singleton();

        $this->assertNull($row->key, 'DB wins once the row exists — env must not refill cleared values (spec D1).');
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $row = S3Connection::singleton();
        $row->forceFill(['key' => 'plain-key', 'secret' => 'plain-secret'])->save();

        $this->assertSame('plain-key', $row->refresh()->key);
        $this->assertSame('plain-secret', $row->refresh()->secret);

        $raw = DB::table('s3_connections')->first();
        $this->assertStringNotContainsString('plain-key', (string) $raw->key);
        $this->assertStringNotContainsString('plain-secret', (string) $raw->secret);
    }

    public function test_config_hash_covers_connection_fields_only(): void
    {
        $a = S3Connection::configHashFor(['driver' => 's3', 'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'region' => 'auto', 'endpoint' => null, 'use_path_style_endpoint' => true, 'throw' => false]);
        $sameDifferentOrder = S3Connection::configHashFor(['throw' => false, 'endpoint' => null, 'use_path_style_endpoint' => true, 'region' => 'auto', 'bucket' => 'b', 'secret' => 's', 'key' => 'k', 'driver' => 's3']);
        $newSecret = S3Connection::configHashFor(['driver' => 's3', 'key' => 'k', 'secret' => 'OTHER', 'bucket' => 'b', 'region' => 'auto', 'endpoint' => null, 'use_path_style_endpoint' => true, 'throw' => false]);

        $this->assertSame($a, $sameDifferentOrder, 'driver/throw and key order must not matter.');
        $this->assertNotSame($a, $newSecret, 'A changed secret must change the hash.');
    }

    public function test_singleton_guard_rejects_a_second_row(): void
    {
        S3Connection::singleton();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        S3Connection::create(['enabled' => true]);
    }
}
```

- [ ] _*Step 1b: Neutralize AWS\_* in the test environment_*

In `phpunit.xml`, inside `<php>` next to the existing `DB_*` entries, add:

```xml
        <env name="AWS_ACCESS_KEY_ID" value=""/>
        <env name="AWS_SECRET_ACCESS_KEY" value=""/>
        <env name="AWS_DEFAULT_REGION" value=""/>
        <env name="AWS_BUCKET" value=""/>
        <env name="AWS_ENDPOINT" value=""/>
        <env name="AWS_USE_PATH_STYLE_ENDPOINT" value=""/>
```

Without this, the developer's real `.env` credentials auto-seed an enabled row in every test and `Storage::build()` would touch the live bucket.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/S3ConnectionTest.php`
Expected: FAIL — `Class "App\Models\S3Connection" not found`.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('s3_connections', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('key')->nullable();
            $table->text('secret')->nullable();
            $table->string('bucket')->nullable();
            $table->string('region', 64)->default('auto');
            $table->string('endpoint')->nullable();
            $table->boolean('use_path_style_endpoint')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_error')->nullable();
            // Single-row guard: concurrent web/worker/scheduler first reads
            // race on creation (spec 3.1); the unique constant pins one row.
            $table->boolean('singleton')->default(1)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s3_connections');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Singleton row describing the S3-compatible storage of this installation.
 *
 * Credentials use encrypted casts and must never be serialized to the
 * browser — the settings UI only receives has_* flags plus a masked hint
 * (mirror of WahaConnection).
 *
 * @property bool $enabled
 * @property string|null $key
 * @property string|null $secret
 * @property string|null $bucket
 * @property string $region
 * @property string|null $endpoint
 * @property bool $use_path_style_endpoint
 * @property Carbon|null $last_test_at
 * @property string|null $last_test_status
 * @property string|null $last_test_error
 */
class S3Connection extends Model
{
    protected $fillable = [
        'enabled',
        'key',
        'secret',
        'bucket',
        'region',
        'endpoint',
        'use_path_style_endpoint',
        'last_test_at',
        'last_test_status',
        'last_test_error',
    ];

    protected function casts(): array
    {
        return [
            'key' => 'encrypted',
            'secret' => 'encrypted',
            'enabled' => 'boolean',
            'use_path_style_endpoint' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }

    /**
     * The single storage row. The first creation seeds itself from the env
     * AWS_* disk config (enabled only when complete); afterwards the
     * database is authoritative and env changes are ignored (spec D1).
     */
    public static function singleton(): self
    {
        try {
            return static::query()->firstOrCreate([], self::seedFromEnv());
        } catch (UniqueConstraintViolationException) {
            // A concurrent process won the creation race.
            return static::query()->firstOrFail();
        }
    }

    public function isConfigured(): bool
    {
        return filled($this->key) && filled($this->secret) && filled($this->bucket);
    }

    /**
     * Laravel filesystem disk config for this row. Null fields are omitted;
     * region always resolves (R2 uses "auto").
     *
     * @return array<string, mixed>
     */
    public function toDiskConfig(): array
    {
        return array_filter([
            'driver' => 's3',
            'key' => $this->key,
            'secret' => $this->secret,
            'bucket' => $this->bucket,
            'region' => $this->region ?? 'auto',
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => $this->use_path_style_endpoint,
            'throw' => false,
        ], fn ($value): bool => $value !== null);
    }

    public function configHash(): string
    {
        return self::configHashFor($this->toDiskConfig());
    }

    /**
     * Hash over exactly the connection fields (key, secret, bucket, region,
     * endpoint, use_path_style_endpoint). driver/throw and key order are
     * excluded; enabled and last_test_* never participate (spec 3.1).
     *
     * @param  array<string, mixed>  $diskConfig
     */
    public static function configHashFor(array $diskConfig): string
    {
        $fields = [];
        foreach (['key', 'secret', 'bucket', 'region', 'endpoint', 'use_path_style_endpoint'] as $field) {
            $fields[$field] = $diskConfig[$field] ?? null;
        }
        ksort($fields);

        return hash('sha256', serialize($fields));
    }

    /** @return array<string, mixed> */
    private static function seedFromEnv(): array
    {
        $disk = config('filesystems.disks.s3');

        return [
            'enabled' => filled($disk['key'] ?? null)
                && filled($disk['secret'] ?? null)
                && filled($disk['bucket'] ?? null),
            'key' => $disk['key'] ?? null,
            'secret' => $disk['secret'] ?? null,
            'bucket' => $disk['bucket'] ?? null,
            'region' => filled($disk['region'] ?? null) ? $disk['region'] : 'auto',
            'endpoint' => $disk['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($disk['use_path_style_endpoint'] ?? true),
        ];
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/S3ConnectionTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*create_s3_connections_table* app/Models/S3Connection.php tests/Feature/S3ConnectionTest.php
git commit -m "feat: add s3_connections singleton with encrypted credentials

Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

---

### Task 2: `MediaStorage` service + test helper

**Files:**

- Create: `app/Services/Media/MediaStorage.php`
- Create: `app/Services/Media/MediaStorageException.php` (small `RuntimeException` subclass)
- Delete: nothing yet (`S3Availability` dies in Task 6)
- Modify: `tests/TestCase.php` (add `swapMediaStorageWithFake()`)
- Test: `tests/Feature/MediaStorageTest.php`

**Interfaces:**

- Consumes: `S3Connection::singleton()/isConfigured()/toDiskConfig()/configHashFor()` (Task 1).
- Produces (used by Tasks 3, 4, 5, 6, 7):
    - `MediaStorage::row(): S3Connection`
    - `MediaStorage::configured(): bool`
    - `MediaStorage::credentialsUnreadable(): bool`
    - `MediaStorage::diskFor(string $disk): Illuminate\Filesystem\FilesystemAdapter`
    - `MediaStorage::buildFor(array $diskConfig): Illuminate\Filesystem\FilesystemAdapter`
    - `TestCase::swapMediaStorageWithFake(string $disk = 's3'): void`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\S3Connection;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaStorageException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MediaStorageTest extends TestCase
{
    use RefreshDatabase;

    private function enabledRow(): S3Connection
    {
        return S3Connection::singleton()->forceFill([
            'enabled' => true,
            'key' => 'k',
            'secret' => 's',
            'bucket' => 'b',
        ])->save()
            ? S3Connection::singleton()->refresh()
            : S3Connection::singleton()->refresh();
    }

    public function test_configured_requires_enabled_and_complete_credentials(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]); // no env seed

        $storage = app(MediaStorage::class);

        $this->assertFalse($storage->configured(), 'No row yet with empty env → not configured.');

        S3Connection::singleton()->forceFill(['key' => 'k', 'secret' => 's', 'bucket' => 'b'])->save();
        $this->assertFalse($storage->configured(), 'Complete credentials but disabled → not configured.');

        $this->enabledRow();
        $this->assertTrue($storage->configured());
    }

    public function test_disk_for_s3_reuses_adapter_until_config_changes(): void
    {
        $storage = app(MediaStorage::class);
        $row = $this->enabledRow();

        $first = $storage->diskFor('s3');
        $second = $storage->diskFor('s3');
        $this->assertSame($first, $second, 'Same config hash must reuse the cached adapter.');

        $row->forceFill(['secret' => 'rotated'])->save();
        $third = $storage->diskFor('s3');
        $this->assertNotSame($first, $third, 'A config change must build a fresh adapter (no worker restart).');
    }

    public function test_disk_for_public_is_a_passthrough(): void
    {
        $this->assertSame(
            Illuminate\Support\Facades\Storage::disk('public'),
            app(MediaStorage::class)->diskFor('public'),
        );
    }

    public function test_disk_for_s3_throws_when_not_configured(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]); // no env seed

        $this->expectException(MediaStorageException::class);
        app(MediaStorage::class)->diskFor('s3');
    }

    public function test_undecryptable_credentials_degrade_to_not_configured(): void
    {
        $this->enabledRow();
        // Simulate APP_KEY rotation: ciphertext no longer decrypts.
        DB::table('s3_connections')->update(['secret' => 'not-valid-ciphertext']);

        $storage = app(MediaStorage::class);

        $this->assertTrue($storage->credentialsUnreadable());
        $this->assertFalse($storage->configured(), 'Unreadable credentials must not 500 the hot path.');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/MediaStorageTest.php`
Expected: FAIL — `Class "App\Services\Media\MediaStorage" not found`.

- [ ] **Step 3: Implement the service**

`app/Services/Media/MediaStorageException.php`:

```php
<?php

namespace App\Services\Media;

/**
 * Raised when code asks for the s3 disk while storage is not usable
 * (disabled, incomplete credentials, or unreadable ciphertext). Callers
 * must catch Throwable at every call site and treat it as not-configured
 * (spec 3.2 contract).
 */
final class MediaStorageException extends \RuntimeException {}
```

`app/Services/Media/MediaStorage.php`:

```php
<?php

namespace App\Services\Media;

use App\Models\S3Connection;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Single door for media storage operations. The s3 adapter is built from
 * the s3_connections row via Storage::build() behind a STATIC per-process
 * cache keyed by config hash — instances are resolved per request/job, so
 * instance state would be discarded. row() re-reads the database on every
 * call, so new credentials apply on the next call without restarts (spec D4).
 */
final class MediaStorage
{
    /** @var array<string, FilesystemAdapter> */
    private static array $adapterCache = [];

    /**
     * @param  Closure(array<string, mixed>): FilesystemAdapter|null  $adapterFactory
     */
    public function __construct(private readonly ?Closure $adapterFactory = null) {}

    public function row(): S3Connection
    {
        return S3Connection::singleton();
    }

    public function configured(): bool
    {
        $row = $this->row();

        if (! $row->enabled) {
            return false;
        }

        try {
            return $row->isConfigured();
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return false;
        }
    }

    /**
     * True when the stored ciphertext cannot be decrypted (e.g. APP_KEY
     * rotated). UI renders a "re-enter credentials" state (spec 3.1).
     */
    public function credentialsUnreadable(): bool
    {
        try {
            $this->row()->isConfigured();

            return false;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return true;
        }
    }

    public function diskFor(string $disk): FilesystemAdapter
    {
        if ($disk === 'public') {
            return Storage::disk('public');
        }

        if ($disk !== 's3') {
            throw new MediaStorageException("Unknown media disk [{$disk}].");
        }

        $config = $this->resolvedDiskConfig();

        // The test factory bypasses the static cache entirely so swapped
        // fakes never leak between tests in one process.
        if ($this->adapterFactory instanceof Closure) {
            return ($this->adapterFactory)($config);
        }

        $hash = S3Connection::configHashFor($config);

        return self::$adapterCache[$hash] ??= Storage::build($config);
    }

    /**
     * Ephemeral adapter for probing UNSAVED form values (spec D2). Never
     * cached, never gated on the saved row — but still honors the test
     * factory so probe tests never build real adapters.
     *
     * @param  array<string, mixed>  $diskConfig
     */
    public function buildFor(array $diskConfig): FilesystemAdapter
    {
        if ($this->adapterFactory instanceof Closure) {
            return ($this->adapterFactory)($diskConfig);
        }

        return Storage::build($diskConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedDiskConfig(): array
    {
        $row = $this->row();

        if (! $row->isConfigured()) {
            throw new MediaStorageException('S3 storage is not configured.');
        }

        return $row->toDiskConfig();
    }
}
```

Note: remove the unused `Illuminate\Contracts\Filesystem\Filesystem` import if PHPStan flags it; keep `FilesystemAdapter` as the return type (it matches `Storage::disk()`/`Storage::build()` returns).

- [ ] **Step 4: Add the TestCase helper**

In `tests/TestCase.php`, add:

```php
use App\Services\Media\MediaStorage;
use Illuminate\Support\Facades\Storage;

    /**
     * Storage::fake alone does NOT intercept MediaStorage's Storage::build
     * path — swap the service with a factory-backed instance instead
     * (spec 3.2 test seam).
     */
    protected function swapMediaStorageWithFake(string $disk = 's3'): void
    {
        Storage::fake($disk);
        $this->app->instance(
            MediaStorage::class,
            new MediaStorage(fn () => Storage::disk($disk)),
        );
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/MediaStorageTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Media/MediaStorage.php app/Services/Media/MediaStorageException.php tests/TestCase.php tests/Feature/MediaStorageTest.php
git commit -m "feat: add MediaStorage service with hash-cached s3 adapters

Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

---

### Task 3: Save + clear-secret endpoints, connection props

**Files:**

- Create: `app/Rules/StorageEndpointUrl.php`
- Create: `app/Http/Requests/Settings/UpdateStorageSettingsRequest.php`
- Modify: `app/Http/Controllers/Settings/StorageController.php`
- Modify: `app/Http/Controllers/Settings/SettingsController.php` (connection props)
- Modify: `routes/web.php` (two routes next to `settings.storage.cleanup-run`)
- Test: `tests/Feature/StorageSettingsTest.php` (extend)

**Interfaces:**

- Consumes: `S3Connection::*` (Task 1), `MediaStorage::row()/configured()/credentialsUnreadable()` (Task 2), `WahaUrl::normalize()` (host guard), `SettingsController::maskHint()`.
- Produces:
    - Routes `settings.storage.update` (PUT `settings/storage`), `settings.storage.clear-secret` (DELETE `settings/storage/secret`).
    - `SettingsController::storageConnectionProps(S3Connection $row, MediaStorage $storage): array` with keys `enabled, has_key, has_secret, key_hint, bucket, region, endpoint, use_path_style_endpoint, configured, credentials_unreadable, last_test` — used by the settings page (Task 8 UI) and by `update()`'s redirect payload.

- [ ] **Step 1: Write the failing tests** (append to `tests/Feature/StorageSettingsTest.php`)

```php
use App\Models\S3Connection;

    public function test_save_updates_s3_connection_settings(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        $this->from(route('settings.index'))
            ->put(route('settings.storage.update'), [
                'enabled' => true,
                'key' => 'AKIA-example',
                'secret' => 'top-secret',
                'bucket' => 'media-bucket',
                'region' => 'auto',
                'endpoint' => 'https://acct.r2.cloudflarestorage.com',
                'use_path_style_endpoint' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = S3Connection::singleton()->refresh();
        $this->assertTrue($row->enabled);
        $this->assertSame('AKIA-example', $row->key);
        $this->assertSame('top-secret', $row->secret);
        $this->assertSame('media-bucket', $row->bucket);
        $this->assertSame('https://acct.r2.cloudflarestorage.com', $row->endpoint);
    }

    public function test_save_keeps_blank_secret_and_clears_blank_non_secret_fields(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        S3Connection::singleton()->forceFill([
            'key' => 'old-key', 'secret' => 'old-secret', 'bucket' => 'old-bucket',
            'endpoint' => 'https://old.example.com', 'region' => 'us-east-1',
        ])->save();

        $this->put(route('settings.storage.update'), [
            'enabled' => true,
            'key' => '',
            'secret' => '',
            'bucket' => 'new-bucket',
            'region' => '',
            'endpoint' => '',
            'use_path_style_endpoint' => false,
        ])->assertRedirect();

        $row = S3Connection::singleton()->refresh();
        $this->assertSame('old-secret', $row->secret, 'Blank secret keeps the stored value.');
        $this->assertNull($row->key, 'Blank key clears the stored value.');
        $this->assertNull($row->endpoint, 'Blank endpoint clears the stored value.');
        $this->assertSame('new-bucket', $row->bucket);
        $this->assertSame('auto', $row->region, 'Blank region falls back to auto.');
        $this->assertFalse((bool) $row->use_path_style_endpoint);
    }

    public function test_save_rejects_metadata_and_non_http_endpoints(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        $this->put(route('settings.storage.update'), ['endpoint' => 'http://169.254.169.254/latest/meta-data'])
            ->assertSessionHasErrors('endpoint');

        $this->advanceThrottleWindow();
        $this->put(route('settings.storage.update'), ['endpoint' => 'ftp://storage.example.com'])
            ->assertSessionHasErrors('endpoint');
    }

    public function test_save_invalidates_stale_last_test_result(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        S3Connection::singleton()->forceFill([
            'key' => 'k', 'secret' => 's', 'bucket' => 'b',
            'last_test_status' => 'passed', 'last_test_at' => now(),
        ])->save();

        // Same effective config → result survives.
        $this->put(route('settings.storage.update'), [
            'enabled' => true, 'key' => 'k', 'secret' => '', 'bucket' => 'b',
            'region' => 'auto', 'endpoint' => '', 'use_path_style_endpoint' => true,
        ])->assertRedirect();
        $this->assertSame('passed', S3Connection::singleton()->refresh()->last_test_status);

        // Changed config → result cleared.
        $this->advanceThrottleWindow();
        $this->put(route('settings.storage.update'), [
            'enabled' => true, 'key' => 'k2', 'secret' => '', 'bucket' => 'b',
            'region' => 'auto', 'endpoint' => '', 'use_path_style_endpoint' => true,
        ])->assertRedirect();
        $this->assertNull(S3Connection::singleton()->refresh()->last_test_status);
    }

    public function test_clear_secret_endpoint_removes_only_the_secret(): void
    {
        $this->actingAs($this->admin());

        S3Connection::singleton()->forceFill(['key' => 'k', 'secret' => 's', 'bucket' => 'b'])->save();

        $this->delete(route('settings.storage.clear-secret'))->assertRedirect();

        $row = S3Connection::singleton()->refresh();
        $this->assertNull($row->secret);
        $this->assertSame('k', $row->key);
    }

    public function test_settings_page_exposes_connection_props_without_secrets(): void
    {
        $this->actingAs($this->admin());

        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'AKIA-abcd1234', 'secret' => 'top-secret', 'bucket' => 'b',
        ])->save();

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('storage.connection.enabled', true)
                ->where('storage.connection.has_key', true)
                ->where('storage.connection.has_secret', true)
                ->where('storage.connection.configured', true)
                ->where('storage.connection.credentials_unreadable', false)
                ->where('storage.connection.key_hint', fn ($hint) => str_ends_with((string) $hint, '1234'))
                ->where('storage.connection.bucket', 'b'));

        $response = $this->get(route('settings.index'))->getContent();
        $this->assertStringNotContainsString('top-secret', $response);
        $this->assertStringNotContainsString('AKIA-abcd1234', $response);
    }

    public function test_settings_page_flags_unreadable_credentials(): void
    {
        $this->actingAs($this->admin());

        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        // Simulate APP_KEY rotation.
        \Illuminate\Support\Facades\DB::table('s3_connections')->update(['secret' => 'not-valid-ciphertext']);

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('storage.connection.credentials_unreadable', true)
                ->where('storage.connection.configured', false));
    }

    public function test_storage_routes_redirect_guests(): void
    {
        $this->put(route('settings.storage.update'), [])->assertRedirect('login');
        $this->delete(route('settings.storage.clear-secret'))->assertRedirect('login');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/StorageSettingsTest.php`
Expected: FAIL — `Route [settings.storage.update] not defined`.

- [ ] **Step 3: Implement rule + request**

`app/Rules/StorageEndpointUrl.php`:

```php
<?php

namespace App\Rules;

use App\Services\Waha\WahaUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Storage endpoints share the WAHA host policy (spec 3.3): http(s) only, no
 * userinfo/query/fragment, cloud-metadata and link-local hosts always
 * rejected, private ranges gated by wahabuddy.allow_private_networks.
 */
final class StorageEndpointUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = WahaUrl::normalize(
            is_string($value) ? $value : null,
            (bool) config('wahabuddy.allow_private_networks', true),
        );

        if ($normalized === null) {
            $fail('The endpoint must be a valid http(s) URL and not target metadata or link-local addresses.');
        }
    }
}
```

`app/Http/Requests/Settings/UpdateStorageSettingsRequest.php`:

```php
<?php

namespace App\Http\Requests\Settings;

use App\Rules\StorageEndpointUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStorageSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            // Blank secret keeps the stored value; blank non-secret fields
            // clear it (spec 3.3 per-field semantics).
            'key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret' => ['sometimes', 'nullable', 'string', 'max:512'],
            'bucket' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:64'],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:255', new StorageEndpointUrl],
            'use_path_style_endpoint' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 4: Extend `StorageController`**

Add to `app/Http/Controllers/Settings/StorageController.php` (keep `runCleanup`):

```php
use App\Http\Requests\Settings\UpdateStorageSettingsRequest;
use App\Models\S3Connection;
use App\Services\Media\MediaStorage;

    public function update(UpdateStorageSettingsRequest $request, MediaStorage $storage): RedirectResponse
    {
        $row = $storage->row();
        $previousHash = $this->safeConfigHash($row);

        $input = $request->validated();

        // Stored-value fallbacks must never throw on unreadable ciphertext
        // (APP_KEY rotation): this form is exactly how the admin recovers
        // (spec section 4, row 8 — "tidak 500").
        $row->fill([
            'enabled' => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $row->enabled,
            'key' => array_key_exists('key', $input) ? ($input['key'] !== null && $input['key'] !== '' ? $input['key'] : null) : $this->plainAttribute($row, 'key'),
            // Blank/omitted secret keeps the stored value; removal happens
            // only through the explicit clear-secret action.
            'secret' => filled($input['secret'] ?? null) ? $input['secret'] : $this->plainAttribute($row, 'secret'),
            'bucket' => array_key_exists('bucket', $input) ? ($input['bucket'] !== null && $input['bucket'] !== '' ? $input['bucket'] : null) : $this->plainAttribute($row, 'bucket'),
            'region' => filled($input['region'] ?? null) ? $input['region'] : 'auto',
            'endpoint' => array_key_exists('endpoint', $input) ? ($input['endpoint'] !== null && $input['endpoint'] !== '' ? $input['endpoint'] : null) : $this->plainAttribute($row, 'endpoint'),
            'use_path_style_endpoint' => array_key_exists('use_path_style_endpoint', $input) ? (bool) $input['use_path_style_endpoint'] : $row->use_path_style_endpoint,
        ]);

        // A changed effective config invalidates the stored test result
        // (spec 3.3): the banner must never describe other credentials.
        if ($previousHash !== null && $previousHash !== $this->safeConfigHash($row)) {
            $row->last_test_at = null;
            $row->last_test_status = null;
            $row->last_test_error = null;
        }

        $row->save();

        return back()->with('status', 'storage-updated');
    }

    public function clearSecret(MediaStorage $storage): RedirectResponse
    {
        $storage->row()->forceFill(['secret' => null])->save();

        return back()->with('status', 'storage-secret-cleared');
    }

    /**
     * Encrypted-cast read that degrades to null when the ciphertext is
     * unreadable instead of throwing.
     */
    private function plainAttribute(S3Connection $row, string $attribute): ?string
    {
        try {
            return $row->{$attribute};
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }
    }

    /**
     * configHash only when the current ciphertext is readable.
     */
    private function safeConfigHash(S3Connection $row): ?string
    {
        try {
            return $row->configHash();
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }
    }
```

- [ ] **Step 5: Connection props in `SettingsController`**

Add (and call it from the `storageProps` return array as `'connection' => self::storageConnectionProps(...)` — build props via `app(MediaStorage::class)` inside `storageProps`, which already receives container services):

```php
use App\Models\S3Connection;
use App\Services\Media\MediaStorage;

    /**
     * Everything the S3/R2 connection form shows. Secrets never leave the
     * backend: only has_* flags and a masked hint (mirror of connectionProps).
     *
     * @return array<string, mixed>
     */
    public static function storageConnectionProps(S3Connection $row, MediaStorage $storage): array
    {
        try {
            $hasKey = filled($row->key);
            $keyHint = $hasKey ? self::maskHint($row->key) : null;
            $unreadable = false;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $hasKey = false;
            $keyHint = null;
            $unreadable = true;
        }

        return [
            'enabled' => (bool) $row->enabled,
            'has_key' => $hasKey,
            'has_secret' => $row->secret !== null && ! $unreadable,
            'key_hint' => $keyHint,
            'bucket' => $row->bucket,
            'region' => $row->region ?? 'auto',
            'endpoint' => $row->endpoint,
            'use_path_style_endpoint' => (bool) $row->use_path_style_endpoint,
            'configured' => $storage->configured(),
            'credentials_unreadable' => $unreadable,
            'last_test' => $row->last_test_at === null ? null : [
                'at' => $row->last_test_at->toAtomString(),
                'status' => $row->last_test_status,
                'error' => $row->last_test_error,
            ],
        ];
    }
```

Note: reading `$row->secret` can throw `DecryptException` too — wrap the whole props build in the same try (move `has_secret` inside the try; on catch set `has_secret => false`, `credentials_unreadable => true`). Adjust `storageProps(...)` signature to `storageProps(AppSetting $preferences, SentMediaCleanup $cleanup, MediaStorage $storage)` and update its caller in `index()`.

- [ ] **Step 6: Routes** (in `routes/web.php`, next to cleanup-run)

```php
    Route::put('settings/storage', [StorageController::class, 'update'])
        ->middleware('throttle:10,1')->name('settings.storage.update');
    Route::delete('settings/storage/secret', [StorageController::class, 'clearSecret'])
        ->middleware('throttle:6,1')->name('settings.storage.clear-secret');
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/StorageSettingsTest.php`
Expected: PASS. Also run `vendor/bin/phpunit tests/Feature/SettingsTest.php` (page renders).

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Rules/StorageEndpointUrl.php app/Http/Requests/Settings/UpdateStorageSettingsRequest.php app/Http/Controllers/Settings/StorageController.php app/Http/Controllers/Settings/SettingsController.php routes/web.php tests/Feature/StorageSettingsTest.php
git commit -m "feat: save and clear S3 storage credentials from Settings

Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

---

### Task 4: Storage test endpoint (unsaved form values)

**Files:**

- Create: `app/Http/Requests/Settings/TestStorageSettingsRequest.php`
- Modify: `app/Http/Controllers/Settings/StorageController.php` (add `test`)
- Modify: `routes/web.php` (one route)
- Test: `tests/Feature/StorageSettingsTest.php` (extend)

**Interfaces:**

- Consumes: `MediaStorage::buildFor()/row()` (Task 2), `S3Connection::configHashFor()/configHash()` (Task 1), route name `settings.storage.test`.
- Produces: `POST settings/storage/test` → JSON `{reachable: bool, status: int|null, persisted: bool, note: string}`; persists `last_test_*` only when the resolved form hash matches the saved row.

- [ ] **Step 1: Write the failing tests**

```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

    private function s3FormPayload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'form-key',
            'secret' => 'form-secret',
            'bucket' => 'form-bucket',
            'region' => 'auto',
            'endpoint' => '',
            'use_path_style_endpoint' => true,
        ], $overrides);
    }

    public function test_probe_reports_reachable_and_persists_when_matching_saved_config(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();
        $this->swapMediaStorageWithFake();

        // Saved row carries the SAME credentials the form submits (blank
        // secret falls back to stored — spec D2).
        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'form-key', 'secret' => 'form-secret', 'bucket' => 'form-bucket',
        ])->save();

        Http::fake(['*' => Http::response('wahabuddy storage probe', 200)]);
        config(['filesystems.disks.public.url' => 'https://app.example.test/storage']);

        $response = $this->postJson(route('settings.storage.test'), $this->s3FormPayload(['secret' => '']));

        $response->assertOk()->assertJsonPath('reachable', true)->assertJsonPath('persisted', true);
        $row = S3Connection::singleton()->refresh();
        $this->assertSame('passed', $row->last_test_status);
        $this->assertNotNull($row->last_test_at);
    }

    public function test_probe_does_not_persist_when_form_differs_from_saved_config(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();
        $this->swapMediaStorageWithFake();

        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'other-key', 'secret' => 'form-secret', 'bucket' => 'form-bucket',
        ])->save();

        Http::fake(['*' => Http::response('wahabuddy storage probe', 200)]);

        $this->postJson(route('settings.storage.test'), $this->s3FormPayload())
            ->assertOk()
            ->assertJsonPath('reachable', true)
            ->assertJsonPath('persisted', false);

        $this->assertNull(S3Connection::singleton()->refresh()->last_test_status);
    }

    public function test_probe_reports_failure_with_static_message_without_leaking_the_host(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();
        $this->swapMediaStorageWithFake();

        // Saved config identical to the form → the FAILED result must also
        // persist (hash-gated, not outcome-gated — spec 3.3 step 5).
        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'form-key', 'secret' => 'form-secret', 'bucket' => 'form-bucket',
        ])->save();

        Http::fake(['*' => Http::response('gateway unreachable', 502)]);

        $response = $this->postJson(route('settings.storage.test'), $this->s3FormPayload([
            'endpoint' => 'https://secret-account.r2.cloudflarestorage.com',
        ]));

        $response->assertOk()
            ->assertJsonPath('reachable', false)
            ->assertJsonPath('persisted', true)
            ->assertJsonPath('note', fn ($note) => str_contains((string) $note, 'fetch_failed'));
        $this->assertStringNotContainsString('cloudflarestorage', (string) $response->json('note'));

        $row = S3Connection::singleton()->refresh();
        $this->assertSame('failed', $row->last_test_status);
        $this->assertSame('fetch_failed', $row->last_test_error, 'Stored error is a static code, never a raw message.');
    }

    public function test_probe_reports_write_failure_before_any_fetch(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        $disk = $this->mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->andReturn(false);
        $disk->shouldReceive('delete')->andReturn(true);
        $this->app->instance(
            \App\Services\Media\MediaStorage::class,
            new \App\Services\Media\MediaStorage(fn () => $disk),
        );
        Http::preventStrayRequests();

        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'form-key', 'secret' => 'form-secret', 'bucket' => 'form-bucket',
        ])->save();

        $this->postJson(route('settings.storage.test'), $this->s3FormPayload(['secret' => '']))
            ->assertOk()
            ->assertJsonPath('reachable', false)
            ->assertJsonPath('note', fn ($note) => str_contains((string) $note, 'write_failed'));
    }

    public function test_probe_tests_form_values_even_when_saved_row_is_disabled(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();
        $this->swapMediaStorageWithFake();

        // Saved row unusable — the probe must still test the FORM values.
        Http::fake(['*' => Http::response('wahabuddy storage probe', 200)]);

        $this->postJson(route('settings.storage.test'), $this->s3FormPayload())
            ->assertOk()
            ->assertJsonPath('reachable', true);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/StorageSettingsTest.php --filter test_probe`
Expected: FAIL — `Route [settings.storage.test] not defined`.

- [ ] **Step 3: Implement**

`app/Http/Requests/Settings/TestStorageSettingsRequest.php` — same rules as `UpdateStorageSettingsRequest` minus `enabled` (reuse by extending the class and overriding `rules()` to unset `enabled`).

In `StorageController`:

```php
use App\Http\Requests\Settings\TestStorageSettingsRequest;
use App\Models\S3Connection as S3ConnectionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

    /**
     * Probe possibly-unsaved form values: write a tiny object through an
     * ephemeral adapter, fetch it, always clean up (spec D2). last_test_* is
     * stored — passed OR failed — only when the tested config matches the
     * saved row (gated on hash equality, not on the outcome; spec 3.3).
     */
    public function test(TestStorageSettingsRequest $request, MediaStorage $storage): JsonResponse
    {
        $input = $request->validated();
        $row = $storage->row();

        $diskConfig = array_filter([
            'driver' => 's3',
            'key' => $input['key'] ?? null,
            'secret' => filled($input['secret'] ?? null) ? $input['secret'] : $this->plainAttribute($row, 'secret'),
            'bucket' => $input['bucket'] ?? null,
            'region' => filled($input['region'] ?? null) ? $input['region'] : 'auto',
            'endpoint' => filled($input['endpoint'] ?? null) ? $input['endpoint'] : null,
            'use_path_style_endpoint' => (bool) ($input['use_path_style_endpoint'] ?? true),
            'throw' => false,
        ], fn ($value): bool => $value !== null);

        $path = 'stories/probe-'.Str::uuid().'.txt';

        try {
            $adapter = $storage->buildFor($diskConfig);

            if ($adapter->put($path, 'wahabuddy storage probe') === false) {
                return $this->probeResult($row, false, null, $diskConfig, 'write_failed', 'Writing the probe object to the bucket failed (write_failed).');
            }

            // The fetch URL comes from the SAME ephemeral adapter — never
            // via MediaUrlResolver, which gates on the saved row.
            $url = $adapter->temporaryUrl($path, now()->addMinutes(5));
            $response = Http::timeout(5)->withoutRedirecting()->get($url);

            if (! $response->successful()) {
                return $this->probeResult($row, false, $response->status(), $diskConfig, 'fetch_failed', 'The bucket answered with an error status (fetch_failed).');
            }

            if (trim($response->body()) !== 'wahabuddy storage probe') {
                return $this->probeResult($row, false, $response->status(), $diskConfig, 'content_mismatch', 'The bucket served unexpected bytes for the probe object (content_mismatch).');
            }

            return $this->probeResult($row, true, $response->status(), $diskConfig, null, 'The bucket answered with the probe bytes.');
        } catch (\Throwable) {
            return $this->probeResult($row, false, null, $diskConfig, 'fetch_failed', 'The probe object could not be fetched from the bucket (fetch_failed).');
        } finally {
            try {
                $storage->buildFor($diskConfig)->delete($path);
            } catch (\Throwable) {
                // Best effort; the probe object is tiny.
            }
        }
    }

    /**
     * Static note strings only — never interpolate exception messages, URLs,
     * or query strings (presign signatures) into last_test_error (spec 3.3).
     *
     * @param  array<string, mixed>  $diskConfig
     */
    private function probeResult(
        S3ConnectionModel $row,
        bool $reachable,
        ?int $status,
        array $diskConfig,
        ?string $errorCode,
        string $note,
    ): JsonResponse {
        $matchesSaved = S3ConnectionModel::configHashFor($diskConfig) === $this->safeConfigHash($row);

        if ($matchesSaved) {
            $row->forceFill([
                'last_test_at' => now(),
                'last_test_status' => $reachable ? 'passed' : 'failed',
                'last_test_error' => $errorCode,
            ])->save();
        }

        return response()->json([
            'reachable' => $reachable,
            'status' => $status,
            'persisted' => $matchesSaved,
            'note' => $note,
        ]);
    }
```

Notes:

- `withoutRedirecting()` is the method name in the installed framework (`withoutRedirects()` does not exist and would be swallowed by the catch as a probe failure).
- Reuse `plainAttribute()`/`safeConfigHash()` from Task 3 (`plainSecret` is replaced by it). Delete `plainSecret` if Task 4's earlier draft introduced it.
- Error classification: `fetch_failed` for non-2xx, `content_mismatch` only for 2xx-with-wrong-bytes.

Register the route:

```php
    Route::post('settings/storage/test', [StorageController::class, 'test'])
        ->middleware('throttle:10,1')->name('settings.storage.test');
```

The fake adapter's `temporaryUrl` returns a local URL that `Http::fake(['*' => ...])` intercepts; `swapMediaStorageWithFake()` already routes `buildFor()` through the factory (Task 2).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/StorageSettingsTest.php tests/Feature/MediaStorageTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Settings/TestStorageSettingsRequest.php app/Http/Controllers/Settings/StorageController.php app/Services/Media/MediaStorage.php routes/web.php tests/Feature/StorageSettingsTest.php
git commit -m "feat: probe unsaved S3 storage credentials from Settings

Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

---

### Task 5: `MediaUrlResolver` on `MediaStorage` + disabled-variant messages

**Files:**

- Modify: `app/Services/Media/MediaUrlResolver.php`
- Modify: `app/Jobs/SendStoryJob.php`, `app/Jobs/ProcessBroadcastJob.php`, `app/Actions/Broadcasts/CompleteRecipient.php` (disabled-variant messages only)
- Test: `tests/Feature/MediaTest.php`, `tests/Feature/StorySchedulerTest.php`, `tests/Feature/BroadcastTest.php` (extend)

**Interfaces:**

- Consumes: `MediaStorage::configured()/diskFor('s3')` (Task 2), `swapMediaStorageWithFake()` (Task 2).
- Produces (unchanged public API): `MediaUrlResolver::forSend(MediaFile): ?string`, `forPreview(MediaFile): ?string`. Constructor now takes `MediaStorage` (container-resolved; no call-site changes).

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/MediaTest.php`, replace the `configureS3Credentials()` helper and its usages with a row-based helper, and add the disabled case:

```php
use App\Models\S3Connection;

    /** Enabled storage row + swapped fake adapter — resolver speaks to this. */
    private function enableS3Storage(): void
    {
        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        $this->swapMediaStorageWithFake();
    }
```

Rework `test_media_url_resolution_local_and_signed` to call `$this->enableS3Storage()` before the S3 part (keep the legacy-public assertions as-is; they need no storage row). New test:

```php
    public function test_send_and_preview_urls_are_null_when_storage_is_disabled(): void
    {
        S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        $this->swapMediaStorageWithFake();

        $media = MediaFile::create([
            'disk' => 's3',
            'path' => 'stories/2026/11/pic.jpg',
            'original_name' => 'pic.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'created_by' => $this->admin()->id,
        ]);

        $resolver = app(\App\Services\Media\MediaUrlResolver::class);

        $this->assertNull($resolver->forSend($media), 'Disabled storage must not hand WAHA a URL (held instead).');
        $this->assertNull($resolver->forPreview($media), 'UI renders a placeholder instead of a broken URL.');
    }
```

In `tests/Feature/StorySchedulerTest.php` (mirror in `BroadcastTest.php` for the pause message):

```php
    public function test_send_fails_with_disabled_storage_variant_message(): void
    {
        $this->workingConnection();
        Http::preventStrayRequests();
        $this->swapMediaStorageWithFake();
        \App\Models\S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();

        $media = MediaFile::create([
            'disk' => 's3', 'path' => 'stories/2026/11/x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'created_by' => User::factory()->create()->id,
        ]);

        $story = $this->makeStory(['type' => 'image', 'media_file_id' => $media->id]);
        $this->runJob($story);

        $story = $story->fresh();
        $this->assertSame(StoryStatus::Failed, $story->status);
        $this->assertSame('media_unavailable', $story->last_error_code);
        $this->assertStringContainsString('disabled or not configured', (string) $story->last_error);
    }
```

BroadcastTest equivalent asserts the paused campaign log `paused_media_unavailable` message contains `disabled or not configured` (media row with `disk => 's3'`).

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/MediaTest.php --filter test_send_and_preview_urls_are_null_when_storage_is_disabled`
Expected: FAIL — forSend currently returns a presigned URL from config-based fake.

- [ ] **Step 3: Implement**

`MediaUrlResolver` — replace `Storage`-based resolution:

```php
final class MediaUrlResolver
{
    private const SIGNED_URL_TTL_MINUTES = 15;

    public function __construct(private readonly MediaStorage $storage) {}

    public function forSend(MediaFile $media): ?string
    {
        if ($media->purged_at !== null || $media->disk !== 's3' || ! $this->storage->configured()) {
            return null;
        }

        try {
            return $this->storage->diskFor('s3')
                ->temporaryUrl($media->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        } catch (\Throwable) {
            return null;
        }
    }

    public function forPreview(MediaFile $media): ?string
    {
        if ($media->purged_at !== null) {
            return null;
        }

        try {
            if ($media->disk === 's3') {
                if (! $this->storage->configured()) {
                    return null;
                }

                return $this->storage->diskFor('s3')
                    ->temporaryUrl($media->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
            }

            return $this->storage->diskFor($media->disk)->url($media->path);
        } catch (\Throwable) {
            return null;
        }
    }
}
```

Delete the private `diskSupportsTemporaryUrls()` helper. In `SendStoryJob` and `ProcessBroadcastJob`/`CompleteRecipient`, the message becomes conditional on the injected `MediaStorage` (same code `media_unavailable`):

```php
// $storage is MediaStorage, injected via the existing handle(...) parameter
// lists (jobs) — add `MediaStorage $storage` as the LAST parameter — or via
// the constructor (CompleteRecipient already constructor-injects its deps).
$disabled = $storage->configured() === false && $media->disk === 's3';

$message = $disabled
    ? 'S3 storage is disabled or not configured (Settings → Storage); re-enable it, then retry.'
    : 'The media file is no longer available (purged by cleanup or stored on the removed local disk); upload new media and edit the story.';
```

**Update the test helpers that call `handle()` positionally.** Both jobs' `handle()` gains a 7th parameter, so append it in `tests/Feature/StorySchedulerTest.php::runJob()` and `tests/Feature/BroadcastTest.php::runJob()`:

```php
            app(\App\Services\Media\MediaUrlResolver::class),
            app(\App\Services\Media\MediaStorage::class),
        );
```

`CompleteRecipient` receives `MediaStorage` through its existing constructor (add it to the promoted constructor parameters; the container resolves it automatically everywhere the action is used).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/MediaTest.php tests/Feature/StorySchedulerTest.php tests/Feature/BroadcastTest.php`
Expected: PASS (legacy-message tests keep passing — the variant only fires when storage is disabled).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Media/MediaUrlResolver.php app/Jobs/SendStoryJob.php app/Jobs/ProcessBroadcastJob.php app/Actions/Broadcasts/CompleteRecipient.php tests/Feature/MediaTest.php tests/Feature/StorySchedulerTest.php tests/Feature/BroadcastTest.php
git commit -m "feat: resolve media URLs through MediaStorage with disabled variant

Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

---

### Task 6: Upload/destroy gate, remove old probe + `S3Availability`

**Files:**

- Modify: `app/Http/Controllers/MediaController.php` (store gate via `MediaStorage`; delete `testStorage`)
- Delete: `app/Services/Media/S3Availability.php`
- Modify: `app/Http/Controllers/Settings/SettingsController.php` (capabilities from the row, until Task 8 removes them)
- Modify: `routes/web.php` (remove `media/test-storage`)
- Test: `tests/Feature/MediaTest.php` (rework)

**Interfaces:**

- Consumes: `MediaStorage::configured()/diskFor('s3')` (Task 2), `swapMediaStorageWithFake()`.
- Produces: upload path fully on `MediaStorage`; old endpoint gone (Task 8 UI uses `settings.storage.test`).

- [ ] **Step 1: Rework tests (RED)**

In `MediaTest`: replace `configureS3Credentials()` with `enableS3Storage()` from Task 5 everywhere; rework:

```php
    public function test_upload_returns_422_when_s3_is_not_configured(): void
    {
        // Pin the env seed empty AND swap a fake adapter so no real
        // adapter is ever built (the dev .env may carry live credentials
        // into the testing config despite the phpunit.xml neutralization).
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $this->swapMediaStorageWithFake();
        $this->actingAs($this->admin());

        $this->postJson(route('media.store'), ['file' => $this->jpeg()])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'disabled or not configured'));

        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_upload_returns_422_when_s3_is_disabled_but_configured(): void
    {
        S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        $this->actingAs($this->admin());

        $this->postJson(route('media.store'), ['file' => $this->jpeg()])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'disabled or not configured'));

        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_upload_returns_422_when_storage_write_fails(): void
    {
        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        Storage::fake('s3');
        $disk = $this->mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->andReturn(false);
        $this->app->instance(
            \App\Services\Media\MediaStorage::class,
            new \App\Services\Media\MediaStorage(fn () => $disk),
        );
        $this->actingAs($this->admin());

        $this->postJson(route('media.store'), ['file' => $this->jpeg()])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'Storing the upload failed'));

        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_old_storage_probe_route_is_removed(): void
    {
        $this->actingAs($this->admin());

        $this->postJson('/media/test-storage')->assertNotFound();
    }
```

Delete `test_storage_probe_cleans_up_even_when_fetch_fails` and `test_storage_probe_reports_unconfigured_s3_as_unreachable` (superseded by Task 4).

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/MediaTest.php`
Expected: FAIL (gate message differs; old route still 200).

- [ ] **Step 3: Implement**

`MediaController::store`:

```php
    public function __construct(
        private readonly MediaUrlResolver $urls,
        private readonly MediaStorage $storage,
    ) {}

    // in store(), after MIME detection:
    if (! $this->storage->configured()) {
        return $this->uploadFailure('file', 'S3 storage is disabled or not configured. Enable and configure it in Settings → Storage & auto-cleanup.');
    }

    $diskName = 's3';
    // ...
    $stored = $this->storage->diskFor($diskName)->put($path, $file->getContent());
```

Wrap the `put` in the existing try/catch (keep the current `Throwable` → `$stored = false` handling). Delete `testStorage()` and its route line in `routes/web.php`. Delete `app/Services/Media/S3Availability.php`. In `SettingsController::storageProps`, replace the `S3Availability` capabilities block with row-derived flags (temporary until Task 8):

```php
            'capabilities' => [
                's3_adapter_installed' => true, // hard composer dependency since the S3-only switch
                's3_credentials_present' => $storage->row()->isConfigured(),
            ],
```

Guard `isConfigured()` with the same `DecryptException` try/catch as Task 3.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/ tests/Unit/`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: gate uploads on MediaStorage and remove env-based S3Availability

Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

---

### Task 7: Per-disk disable semantics in cleanup + destroy

**Files:**

- Modify: `app/Services/Media/SentMediaCleanup.php` (constructor-inject `MediaStorage`)
- Modify: `app/Console/Commands/PruneOrphanMedia.php`
- Modify: `app/Http/Controllers/MediaController.php` (`destroy`)
- Test: `tests/Feature/MediaCleanupTest.php`, `tests/Feature/MediaTest.php` (extend)

**Interfaces:**

- Consumes: `MediaStorage::configured()/diskFor()` (Task 2).
- Produces: behavior only — `purge(): bool` skips `disk='s3'` rows when `!configured()`; orphan prune skips (keeps row + bytes); `destroy` returns 409 for those rows.

- [ ] **Step 1: Write the failing tests** (add to `MediaCleanupTest` — read it first and follow its fixture style)

Add this helper alongside the existing eligible-fixture helper (it mirrors that helper's story/broadcast terminal-state shape, parameterized by disk):

```php
    /**
     * A purge-eligible media row on the given disk with real fake-disk bytes.
     */
    private function eligibleMediaOnDisk(string $disk): \App\Models\MediaFile
    {
        \Illuminate\Support\Facades\Storage::fake($disk);
        \Illuminate\Support\Facades\Storage::disk($disk)->put("stories/2026/11/{$disk}.jpg", 'x');

        $media = \App\Models\MediaFile::create([
            'disk' => $disk,
            'path' => "stories/2026/11/{$disk}.jpg",
            'original_name' => "{$disk}.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'created_by' => $this->admin()->id,
        ]);

        $story = \App\Models\Story::create([
            'type' => 'image',
            'media_file_id' => $media->id,
            'scheduled_at' => now()->subDays(10),
            'status' => \App\Enums\StoryStatus::Sent,
            'sent_at' => now()->subDays(10),
            'connection_revision' => 1,
        ]);
        $story->forceFill(['updated_at' => now()->subDays(4)])->save();

        return $media;
    }
```

(If `MediaCleanupTest` has no `admin()` helper, add the same one used in `MediaTest`.)

```php
    public function test_sent_media_purge_skips_s3_rows_but_keeps_purging_public_rows_when_storage_is_disabled(): void
    {
        $this->swapMediaStorageWithFake();
        \App\Models\S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();

        $s3Media = $this->eligibleMediaOnDisk('s3');   // helper: same shape as existing eligible fixtures, disk='s3', Storage::fake('s3') file
        $publicMedia = $this->eligibleMediaOnDisk('public');

        $purged = app(\App\Services\Media\SentMediaCleanup::class)->purge($s3Media);

        $this->assertFalse($purged);
        $this->assertNull($s3Media->refresh()->purged_at, 'Toggle never deletes or tombstones S3 files (spec D3).');
        $this->assertTrue(app(\App\Services\Media\SentMediaCleanup::class)->purge($publicMedia));
        $this->assertNotNull($publicMedia->refresh()->purged_at, 'Legacy public rows keep purging while storage is disabled.');
    }

    public function test_orphan_prune_skips_s3_rows_entirely_when_storage_is_disabled(): void
    {
        $this->swapMediaStorageWithFake();
        \App\Models\S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();

        $orphan = MediaFile::create([
            'disk' => 's3', 'path' => 'stories/2026/11/orphan.jpg', 'original_name' => 'orphan.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'created_by' => $this->admin()->id,
        ]);
        $orphan->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('wahabuddy:prune-orphan-media')->assertSuccessful();

        $this->assertDatabaseHas('media_files', ['id' => $orphan->id], 'Row survives for the next weekly run.');
    }
```

In `MediaTest`:

```php
    public function test_destroy_refuses_s3_media_while_storage_is_disabled(): void
    {
        $this->swapMediaStorageWithFake();
        \App\Models\S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        $this->actingAs($this->admin());

        $media = MediaFile::create([
            'disk' => 's3', 'path' => 'stories/2026/11/x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'created_by' => $this->admin()->id,
        ]);

        $this->deleteJson(route('media.destroy', $media))->assertConflict();
        $this->assertDatabaseHas('media_files', ['id' => $media->id]);
    }
```

(The existing `test_referenced_media_cannot_be_deleted` public-disk cases keep passing unchanged.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/MediaCleanupTest.php tests/Feature/MediaTest.php --filter "disabled"`
Expected: FAIL.

- [ ] **Step 3: Implement**

`SentMediaCleanup`:

```php
    public function __construct(private readonly MediaStorage $storage) {}

    public function purge(MediaFile $media): bool
    {
        // Disable semantics are per-row (spec D3): only s3 rows are blocked.
        if ($media->disk === 's3' && ! $this->storage->configured()) {
            return false;
        }

        try {
            $disk = $this->storage->diskFor($media->disk);

            if ($disk->delete($media->path) === false && $disk->exists($media->path)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $media->fill(['purged_at' => now()])->save();

        return true;
    }
```

`PruneOrphanMedia` — inject `MediaStorage $storage` in `handle(...)`, and inside the per-file loop, before any deletion:

```php
            if ($media->disk === 's3' && ! $storage->configured()) {
                continue; // skip bytes AND row — retried next weekly run (spec 3.5)
            }
            $disk = $storage->diskFor($media->disk);
```

`MediaController::destroy` — before deleting:

```php
        if ($media->disk === 's3' && ! $this->storage->configured()) {
            return response()->json(['message' => 'Storage is disabled; the file cannot be removed right now.'], 409);
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Media/SentMediaCleanup.php app/Console/Commands/PruneOrphanMedia.php app/Http/Controllers/MediaController.php tests/Feature/MediaCleanupTest.php tests/Feature/MediaTest.php
git commit -m "feat: per-disk disable semantics for cleanup and destroy

Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

---

### Task 8: Settings UI, types, docs, full verification

**Files:**

- Modify: `resources/js/components/settings/storage-card.tsx`
- Modify: `resources/js/components/settings/connection-card.tsx` (it imports the deleted `media/test-storage` route — cleanup mandatory or tsc fails)
- Modify: `resources/js/types/wahabuddy.ts`
- Modify: `app/Http/Controllers/Settings/SettingsController.php` (drop `capabilities` block)
- Modify: `.env.example`, `README.md`, `docs/deployment.md`, `docs/wahabuddy-spec.md`
- Regenerate: `php artisan wayfinder:generate --no-interaction`, `npm run build`

**Interfaces:**

- Consumes: `storage.connection` props (Task 3), routes `settings.storage.update/test/clear-secret` (Tasks 3–4).
- Produces: final UI; docs updated; no `capabilities` in props/types.

- [ ] **Step 1: Types** — in `resources/js/types/wahabuddy.ts`, replace the `capabilities` field on `StorageSettings` with:

```ts
export type StorageConnection = {
    enabled: boolean;
    has_key: boolean;
    has_secret: boolean;
    key_hint: string | null;
    bucket: string | null;
    region: string;
    endpoint: string | null;
    use_path_style_endpoint: boolean;
    configured: boolean;
    credentials_unreadable: boolean;
    last_test: {
        at: string;
        status: string | null;
        error: string | null;
    } | null;
};

export type StorageSettings = {
    connection: StorageConnection;
    cleanup: {/* unchanged */};
    // ...rest unchanged
};
```

- [ ] **Step 2: storage-card.tsx** — replace the static S3 status block with the connection form: toggle `enabled`; inputs for key, secret (password, placeholder `has_secret ? 'Configured — leave empty to keep' : ''`), bucket, region (default `auto`), endpoint, checkbox path-style; `Test storage` button posting FORM values via `postJson(storageTestRoute.url(), payload)` and toasting `reachable` + `persisted`; `Clear secret` button that asks for confirmation first (match the confirm pattern used elsewhere in the settings pages) before `router.delete`; `credentials_unreadable` banner; last-test line. **Save submits two requests** — connection slice via `form` fields put to `updateStorageRoute.url()`, cleanup slice via the existing `updatePreferencesRoute.url()` put (each with its own toast). Remove the `s3Available`/`capabilities` logic and the old `testStorage` route import; import the three new routes from `@/routes/settings/storage`.

- [ ] **Step 2b: connection-card.tsx** — remove the storage-probe leftovers that pointed at the deleted route: the `testStorage` import from `@/routes/media`, the `testingStorage` state, and the storage-probe button/handler (the storage card owns probing now).

- [ ] **Step 3: Backend props cleanup** — remove the `capabilities` block from `storageProps`; update `tests/Feature/StorageSettingsTest.php::test_settings_page_exposes_storage_summary` (drop any capabilities assertion; the connection props test from Task 3 already covers the new shape).

- [ ] **Step 4: Run backend tests + page render**

```bash
php artisan wayfinder:generate --no-interaction
vendor/bin/phpunit tests/Feature/
```

Expected: PASS.

- [ ] **Step 5: Frontend gates**

```bash
npm run typecheck && npm run check && npm run build
```

Expected: all pass with ZERO new errors (baseline is zero after the 2026-11-11 regeneration fix).

- [ ] **Step 6: Docs**

- `.env.example` AWS block comment → `# Seeds Settings -> Storage on first run only; the database is the source of truth afterwards. Keep these until the install runs stably on DB-based storage.`
- `README.md` quick start: `# edit .env: APP_URL, DB_* (S3/R2 media is configured later in Settings → Storage)`; drop the `storage:link` "removed local-disk mode" wording if desired — keep the line itself (legacy previews).
- `docs/deployment.md`: replace the "Media storage is S3-only" paragraph's env instructions with Settings-page instructions; add the rollback note from spec 3.7 AND the upgrade-path paragraph: _installs with `AWS_*` already filled auto-seed an enabled row on first read (zero action); installs without them configure everything from Settings → Storage; `APP_KEY` must remain stable — the credentials are encrypted with it (same requirement as the stored WAHA credentials)._
- `docs/wahabuddy-spec.md` sections E & H: credentials live encrypted in `s3_connections`; env `AWS_*` seeds only; test results bind to `configHash`.

- [ ] **Step 7: Full verification gate**

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --dirty --format agent
CI=true npm run check
```

Expected: PHPUnit green (only the two pre-existing RegistrationTest skips), PHPStan 0 errors, Pint pass, vp check pass.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: manage S3/R2 storage from the Settings page

Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

---

## Self-Review (completed)

- **Spec coverage:** D1→Task 1 (seeding tests); D2→Task 4; D3→Tasks 5,6,7; D4→Task 2; 3.1→Task 1; 3.2→Task 2; 3.3→Tasks 3,4; 3.4→Task 3 (+8 drop of capabilities); 3.5→Tasks 5,6,7; 3.6→Task 8; 3.7→Task 8; section 4 edge cases→Tasks 1–7 tests; section 5 cycles S1–S8 map to Tasks 1–8.
- **Placeholders:** none — every step carries code or exact commands. Task 8 Step 2 is UI work described structurally (component follows the existing form patterns in the same file); the props contract and routes are pinned in Tasks 3–4.
- **Type consistency:** `MediaStorage::diskFor/buildFor/configured/row/credentialsUnreadable`, `S3Connection::singleton/isConfigured/toDiskConfig/configHash/configHashFor`, `swapMediaStorageWithFake`, route names `settings.storage.update|test|clear-secret`, error strings `write_failed|fetch_failed|content_mismatch`, code `media_unavailable` — identical across tasks.
- **Adversarial verification (2026-11-11, 3-agent workflow):** fixed 4 blockers (missing `encrypted` casts; missing `Model` import; nonexistent `withoutRedirects()` → `withoutRedirecting()`; `connection-card.tsx` still importing the deleted probe route), 4 important (failed probes now persist `last_test_*` when the hash matches; `phpunit.xml` neutralizes `AWS_*` so the developer's live `.env` never seeds tests or touches the real bucket; `runJob()` helpers updated for the new `handle()` parameter; upload-unconfigured test pins empty config + fake adapter), and 8 minor (DecryptException-safe `update()` fallbacks, `fetch_failed` classification by status, full `eligibleMediaOnDisk` helper, Clear-secret confirmation, pint fix-mode gate, docs upgrade-path/APP_KEY notes, write_failed + persisted-error + `credentials_unreadable` props tests, `buildFor` factory honored from Task 2).
