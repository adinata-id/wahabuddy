# Desain: Pengaturan S3/R2 dari Halaman Settings (DB-only)

- **Tanggal:** 2026-11-11 (revisi 2 setelah review 5-agen: verifikasi fakta + kritik security/konsistensi/testability/operasional)
- **Status:** disetujui di sesi brainstorming; menunggu review spec
- **Konteks:** lanjutan dari commit `1b380d5` (media storage S3-only; local disk & tunnel dihapus). Saat ini kredensial S3 hanya bisa diisi lewat env `AWS_*` — upload ditolak 422 sampai env diisi dan proses direstart.

## 1. Masalah & tujuan

Kredensial S3/R2 harus bisa dikonfigurasi, diuji, dan diaktifkan/dinonaktifkan dari **Settings → Storage & auto-cleanup**, tanpa mengedit `.env` dan tanpa restart proses (web, queue worker, scheduler).

## 2. Keputusan desain (terkunci dari sesi brainstorming)

- **D1 — DB-only.** Database adalah sumber kebenaran tunggal. Env `AWS_*` hanya menyemai baris pertama saat dibuat (`enabled` otomatis `true` bila key+secret+bucket terisi). Setelah baris ada, DB menang — termasuk nilai yang sengaja dikosongkan admin; env tidak pernah mengisi ulang.
- **D2 — Test menguji nilai form.** Tombol _Test storage_ memakai nilai yang baru diketik (belum disimpan), pola yang sama dengan _Test connection_ WAHA: hasil `last_test_*` hanya dipersist bila nilai form identik dengan config tersimpan (`configHash`).
- **D3 — Nonaktif = blokir semua operasi S3, per baris.** Toggle off menutup operasi **baris `disk = 's3'` saja**: upload ditolak 422; `forSend()` `null` (story gagal held, campaign image pause — jalur eksisting); cleanup melewati baris S3. **Baris legacy `disk = 'public'` tidak terpengaruh** (preview & cleanup tetap jalan). Toggle tidak pernah menghapus file maupun baris.
- **D4 — Pendekatan B (service, bukan mutasi config global).** Adapter S3 dibangun per proses via `Storage::build()` dari baris DB dan di-cache **statis** dengan kunci hash config; hash berubah → adapter dibangun ulang pada panggilan berikutnya. Worker memakai kredensial baru tanpa `queue:restart` (mirror filosofi `ConnectionRepository` yang membaca DB per eksekusi job).

## 3. Arsitektur

### 3.1 Data — tabel `s3_connections` (singleton, mirror `waha_connections`)

| Kolom                     | Tipe                               | Keterangan                             |
| ------------------------- | ---------------------------------- | -------------------------------------- |
| `enabled`                 | boolean, default `false`           | toggle aktif/nonaktif                  |
| `key`                     | text, nullable, **encrypted cast** | access key ID                          |
| `secret`                  | text, nullable, **encrypted cast** | secret access key                      |
| `bucket`                  | string(255), nullable              |                                        |
| `region`                  | string(64), default `'auto'`       | `auto` untuk R2                        |
| `endpoint`                | string(255), nullable              | endpoint akun R2 / S3-compatible       |
| `use_path_style_endpoint` | boolean, default `true`            |                                        |
| `last_test_at`            | timestamp, nullable                | mirror `waha_connections`              |
| `last_test_status`        | string, nullable                   | `'passed'`/`'failed'`                  |
| `last_test_error`         | text, nullable                     | pesan statis (lihat 3.3)               |
| `singleton`               | boolean, `default(1)`, **unique**  | penjaga satu baris (race antar proses) |

Kolom `url` (custom domain) **dihapus dari cakupan**: send selalu presigned dan preview presigned sudah memadai; menghindari field tanpa konsumen.

Model `App\Models\S3Connection`:

- `singleton(): self` — `firstOrCreate([])` dengan retry-on-duplicate (kolom `singleton` unique menjaga race web/worker/scheduler saat instalasi baru pertama kali membaca baris bersamaan). Pembuatan pertama menyemai dari `config('filesystems.disks.s3')` (nilai env `AWS_*`): key, secret, bucket, endpoint, `use_path_style_endpoint`, dan region dengan fallback `filled(region) ?: 'auto'`; `enabled = filled(key) && filled(secret) && filled(bucket)`.
- Casts: `key`/`secret` → `encrypted`; `enabled`/`use_path_style_endpoint` → boolean; `last_test_at` → datetime.
- `isConfigured(): bool` — `filled(key) && filled(secret) && filled(bucket)`.
- `toDiskConfig(): array` — `['driver' => 's3', 'throw' => false, 'region' => $region ?? 'auto', ...field yang terisi (key/secret/bucket/endpoint/use_path_style_endpoint)]`; TANPA `enabled`, `last_test_*`, `singleton`.
- `configHash(): string` dan `static configHashFor(array $resolved): string` — satu jalur kode yang sama — hash atas **tepat** field `toDiskConfig` (key, secret, bucket, region, endpoint, use_path_style_endpoint); `enabled` dan `last_test_*` dikecualikan. Hash satu arah atas nilai (termasuk secret), aman disimpan bandingkan.
- **DecryptException:** nilai terenkripsi yang tak terbaca (mis. `APP_KEY` berganti) tidak boleh 500 di hot path — `MediaStorage::row()` menangkap `DecryptException` saat membaca dan memperlakukan baris sebagai tidak terkonfigurasi dengan state UI khusus _"credentials unreadable — re-enter them"_.

### 3.2 Service `App\Services\Media\MediaStorage` (menggantikan & menghapus `S3Availability`)

```php
final class MediaStorage
{
    public function __construct(private readonly ?Closure $adapterFactory = null) {}
    public function row(): S3Connection;              // DB read per panggilan (bukan cache boot)
    public function configured(): bool;               // row->enabled && row->isConfigured()
    public function diskFor(string $disk): FilesystemAdapter;
    public function buildFor(array $diskConfig): FilesystemAdapter; // ephemeral, untuk probe nilai form
}
```

- `diskFor('public')` → `Storage::disk('public')` (baris legacy: preview & cleanup tetap jalan — lihat D3).
- `diskFor('s3')` → adapter dari **cache statis per-proses** (array `static` di dalam class — instance di-resolve container per request/job, jadi cache instance-properti akan terbuang); kunci cache = `configHashFor(toDiskConfig())`. Hash berubah → bangun ulang via `Storage::build($config)`. `row()` tetap DB read per panggilan sehingga kredensial baru terlihat pada panggilan berikutnya.
- Kontrak `diskFor('s3')`: melempar `RuntimeException` bila `!isConfigured()`. Gate dan pemanggilan **tidak atomik** (row bisa berubah di antaranya) — karena itu **setiap call site wajib menangkap `Throwable` dan memperlakukan kegagalan sebagai tidak-terkonfigurasi**: `forPreview` → `null`/placeholder, `destroy` → 409, `purge` → `false`, orphan prune → skip baris (selaras catch-all yang sudah ada di resolver).
- `buildFor()` membangun adapter ephemeral dari config hasil resolve form — dipakai endpoint test; **bukan** via cache.
- **Seam injeksi test:** `Storage::fake('s3')` sendiri TIDAK mencegat `Storage::build()`. Feature test mengganti service: `$this->app->instance(MediaStorage::class, new MediaStorage(fn () => Storage::disk('s3')))` setelah `Storage::fake('s3')`. Helper bersama `swapMediaStorageWithFake()` ditambahkan di `tests/TestCase.php`.
- Seluruh konsumen bermigrasi ke service ini: `MediaController::store`/`destroy`, `MediaUrlResolver`, `SentMediaCleanup::purge`, `PruneOrphanMedia`, props settings. `MediaController::testStorage` dihapus (digantikan 3.3). `S3Availability` dihapus.

### 3.3 HTTP — extend `Settings\StorageController` yang sudah ada

Controller ini sudah memiliki `runCleanup()` (route `settings.storage.cleanup-run`) — **dipertahankan**; ditambah tiga method mirror `ConnectionController`. Semua route didaftarkan di dalam grup `['auth','verified']` di `routes/web.php` (sama seperti route settings lain).

- `PUT settings/storage` (throttle `10,1`) — `UpdateStorageSettingsRequest`:
    - Rules: `enabled: boolean`; `key: nullable|string|max:255`; `secret: nullable|string|max:512`; `bucket: nullable|string|max:255`; `region: nullable|string|max:64`; `endpoint: nullable|url:http,https|max:255` + guard SSRF (di bawah); `use_path_style_endpoint: boolean`. Semua `sometimes`-style partial-save (konvensi kartu settings).
    - **Semantik per field saat resolve form:** `secret` blank → pertahankan tersimpan (penghapusan hanya via Clear secret); `key`/`bucket`/`endpoint` blank → nilai tersimpan dikosongkan (`null`) — field non-secret editable langsung; `region` blank → `'auto'`; `use_path_style_endpoint`/`enabled` selalu dari form.
    - **Guard SSRF endpoint** (paritas `WahaUrl`, bukan "bebas LAN"): endpoint selalu ditolak bila host-nya cloud-metadata (mis. `169.254.169.254`, `metadata.google.internal`) atau link-local; rentang privat (RFC1918/ULA) hanya diizinkan bila `wahabuddy.allow_private_networks` (default true). Implementasi mengekstrak/memakai kembali guard dari `App\Services\Waha\WahaUrl`.
    - **Invalidasi hasil test:** bila `configHash` efektif pasca-save ≠ sebelum save, `last_test_at/status/error` **dikosongkan** — banner hasil test tidak boleh menampilkan hasil untuk kredensial yang sudah bukan yang diuji.
- `POST settings/storage/test` (throttle `10,1`) — `TestStorageSettingsRequest` (validasi sama tanpa `enabled`):
    1. Resolve form (secret blank → fallback tersimpan; aturan per-field sama).
    2. `buildFor($resolvedConfig)` → tulis `stories/probe-{uuid}.txt` berisi konstanta `wahabuddy storage probe`; `put() === false` → langsung `reachable=false`, error statis `write_failed` (tanpa fetch).
    3. **URL fetch diambil dari adapter ephemeral yang sama** — `$adapter->temporaryUrl($path, now()->addMinutes(5))` — TIDAK via `MediaUrlResolver` (resolver digate baris tersimpan dan akan `null` untuk nilai form yang belum disimpan).
    4. `Http::timeout(5)` fetch **tanpa mengikuti redirect**; `reachable = 2xx && body === 'wahabuddy storage probe'`; hapus probe di `finally`.
    5. `last_test_*` dipersist **hanya** bila `configHashFor($resolved) === $saved->configHash()` (mirror `recordTestResult`).
    6. Response `{reachable, status, persisted, note}`.
    - **`last_test_error` hanya pesan statis** dari himpunan tetap (`write_failed`, `fetch_failed`, `content_mismatch`) dengan copy kalengan — tidak pernah menginterpolasi pesan exception, URL, atau query string (bisa berisi kredensial presign). Test S4 memassert error tidak mengandung host endpoint maupun `X-Amz-`.
- `DELETE settings/storage/secret` (throttle `6,1`) — `clearSecret()` mirror `clearApiKey`.
- Route + method lama `media/test-storage` dihapus.

### 3.4 Props ke UI (mirror `connectionProps`)

`storage.connection = { enabled, has_key, has_secret, key_hint, bucket, region, endpoint, use_path_style_endpoint, configured, credentials_unreadable, last_test: {at, status, error}|null }`.

- Key/secret **tidak pernah** dikirim ke browser; hanya `has_key`/`has_secret` + `key_hint` (masked, `SettingsController::maskHint`).
- `configured` dihitung server-side; `credentials_unreadable` menandai DecryptException (UI: "credentials unreadable — re-enter them").
- Blok `capabilities` lama (`s3_adapter_installed`, `s3_credentials_present`) dihapus.

### 3.5 Integrasi pipeline

- **`MediaUrlResolver`** (inject `MediaStorage`):
  `forSend`: purged → `null`; disk ≠ `s3` → `null`; `!configured()` → `null`; else `diskFor('s3')->temporaryUrl(15 menit)` dalam try/catch → `null`.
  `forPreview`: `s3` + (`!configured()` || `credentials_unreadable`) → `null` (UI render placeholder); `s3` + configured → presigned; disk lain → URL disk biasa (legacy `public` tetap bisa dipreview).
- **Upload** (`MediaController::store`): gate `!configured()` → 422 _"S3 storage is disabled or not configured. Enable and configure it in Settings → Storage & auto-cleanup."_ Tulis via `diskFor('s3')`.
- **Penyebab kegagalan dibedakan** saat `!configured()` (bukan media hilang): pesan story/campaign menjadi _"S3 storage is disabled or not configured (Settings → Storage); re-enable it, then retry/resume."_ — kode error tetap `media_unavailable` (satu jalur UI), hanya pesannya yang membedakan penyebab. `SendStoryJob`, `ProcessBroadcastJob`, dan pesan pause `CompleteRecipient` memakai varian pesan ini.
- **Cleanup — gate per baris (D3), bukan menyeluruh:**
    - `SentMediaCleanup::purge`: baris `disk = 's3'` + `!configured()` → `return false` → masuk akunting `files_failed` yang sudah ada, `purged_at` tidak diset. Baris `disk` lain → purge normal via `diskFor($media->disk)`.
    - `PruneOrphanMedia`: baris `disk = 's3'` + `!configured()` → **skip baris sepenuhnya** (tidak hapus byte, tidak hapus baris DB — tidak ada akunting `files_failed`/`purged_at` di command ini; baris diulang minggu depan). Baris `disk` lain → perilaku normal.
- **`MediaController::destroy`**: baris `disk = 's3'` + `!configured()` → 409 _"Storage is disabled; the file cannot be removed right now."_ Baris `public` → perilaku normal.

### 3.6 Frontend — `storage-card.tsx`

Bagian atas card menjadi **form koneksi S3/R2**:

- Toggle "S3 storage enabled"; banner `credentials_unreadable` bila ada.
- Input: Access key ID, Secret access key (password; `has_secret` menampilkan hint "configured — leave empty to keep"; tombol **Clear secret** terpisah dengan konfirmasi), Bucket, Region (default `auto`), Endpoint (opsional, R2), checkbox path-style endpoint.
- Tombol **Test storage** → POST nilai form ke `settings/storage/test`, toast hasil termasuk `persisted`.
- Banner hasil test terakhir (waktu + status + error statis).
- **Wiring Save:** satu tombol Save mengirim **dua request** — slice koneksi ke `PUT settings/storage`, slice cleanup ke `PUT settings/preferences` (endpoint yang sudah ada) — masing-masing dengan toast sukses/gagalnya sendiri (konvensi partial-save `sometimes` tetap berlaku di kedua endpoint).

Types `StorageSettings` ditambah `connection`; Wayfinder regenerasi.

### 3.7 Env, docs & catatan operasional

- `.env.example` blok AWS: komentar menjadi _"Seeds Settings → Storage on first run only; the database is the source of truth afterwards. Keep these in .env until the install runs stably on DB-based storage."_
- README quick start & `docs/deployment.md`: AWS_* opsional (seed); konfigurasi utama dari UI.
- `docs/wahabuddy-spec.md` bagian E & H disesuaikan (kredensial storage di DB terenkripsi; env hanya seed; hasil test terikat configHash).
- **Upgrade:** deployment dengan `AWS_*` terisi → baris ter-seed otomatis `enabled=true` (zero-action); tanpa `AWS_*` → baris `disabled`, admin mengisi dari UI. `APP_KEY` harus tetap stabil (encrypted casts — syarat yang sama dengan kredensial WAHA).
- **Rollback:** kode balik ke `1b380d5` menghidupkan lagi gate env-only — upload 422 sampai `AWS_*` dikembalikan di `.env`; `migrate:rollback` menghapus kredensial terenkripsi (isi ulang dari UI setelah maju kembali).

## 4. Error handling & edge cases

| Kasus                                    | Perilaku                                                                                                                       |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Kredensial salah / bucket tak ada        | upload 422 "Storing the upload failed" (path put-gagal yang sudah ada); test `reachable=false` (`write_failed`/`fetch_failed`) |
| Secret dikosongkan saat save             | nilai lama dipertahankan; penghapusan hanya via Clear secret                                                                   |
| Key/bucket/endpoint dikosongkan          | nilai tersimpan dikosongkan (`null`) — non-secret editable langsung                                                            |
| Worker berjalan lama                     | cache statis dikunci hash → kredensial baru otomatis pada panggilan berikutnya                                                 |
| Env berubah setelah baris ada            | diabaikan (D1)                                                                                                                 |
| Storage disabled + baris S3              | preview placeholder, send held (pesan varian disabled), cleanup skip — file utuh                                               |
| Storage disabled + baris legacy `public` | tidak terpengaruh: preview & cleanup normal (D3)                                                                               |
| `APP_KEY` berganti → decrypt gagal       | baris dianggap tidak terkonfigurasi + state `credentials_unreadable`; tidak 500                                                |
| Endpoint metadata/link-local             | selalu ditolak; rentang privat ikut `allow_private_networks`                                                                   |
| Save mengubah config efektif             | `last_test_*` dikosongkan (hasil lama tidak berlaku untuk config baru)                                                         |
| Race pembuatan baris pertama             | kolom `singleton` unique + `firstOrCreate` retry → tepat satu baris                                                            |

## 5. Strategi testing (TDD, per siklus)

Konvensi: pengujian throttled endpoint mengikuti `advanceThrottleWindow()` (`travel(61s)`) dari `StorageSettingsTest`; APP_KEY tersedia via `.env`/CI seperti test `WahaConnection` yang sudah ada.

| Siklus | Cakupan                                                                                                                                                                                                                                                                                                     |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| S1     | `S3Connection`: encrypted roundtrip; seed dari env (termasuk tanpa `AWS_DEFAULT_REGION` → region `'auto'`); env tidak menimpa baris yang ada; `toDiskConfig`/`configHash` (field set tepat, `enabled`/`last_test_*` dikecualikan); race singleton                                                           |
| S2     | `MediaStorage`: matriks `configured()`; cache statis — `diskFor('s3')` mengembalikan instance sama selama config tidak berubah dan instance baru setelah row di-update (`assertNotSame`, tanpa factory injeksi); `diskFor('public')` passthrough; kontrak throw `!isConfigured`                             |
| S3     | Save endpoint: roundtrip; blank-secret keep; blank key/bucket/endpoint mengosongkan; Clear secret; validasi + guard SSRF endpoint (metadata ditolak walau private diizinkan); secret tak pernah muncul di response/props; `key_hint` masked; invalidasi `last_test_*` saat config berubah; guest → redirect |
| S4     | Test endpoint: `reachable=true` via adapter fake + `Http::fake` echo body probe; gagal fetch/write; `persisted` hanya saat form==saved; fallback secret tersimpan; `last_test_error` statis & tanpa host/`X-Amz-`                                                                                           |
| S5     | Upload: 422 disabled; 422 unconfigured; sukses menulis via `swapMediaStorageWithFake()`                                                                                                                                                                                                                     |
| S6     | Resolver: `forSend` null saat disabled (pesan varian disabled di job); presigned saat enabled; `forPreview` null saat disabled; placeholder; `DecryptException` → `credentials_unreadable`                                                                                                                  |
| S7     | Cleanup per-disk: baris `s3` + disabled → `purge` false tanpa `purged_at` & `files_failed`; **baris `public` tetap terpurge saat storage disabled**; orphan prune skip baris s3 tanpa menghapus baris; `destroy` 409 (s3+disabled) & normal (public)                                                        |
| S8     | Route lama `media/test-storage` → 404; wiring UI dua request; typecheck/build/lint                                                                                                                                                                                                                          |

## 6. Di luar cakupan (out of scope)

- Multi-bucket / profil storage berganda.
- Perintah migrasi file legacy `public` → S3.
- Kolom/pengaturan custom domain (`url`) — dihapus dari cakupan (send & preview presigned memadai).
- Pengaturan TTL presigned dari UI.
- Pengujian terhadap bucket S3 nyata di CI (probe tetap lewat adapter fake + Http fake).
