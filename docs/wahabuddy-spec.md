# WahaBuddy — Build Prompt dan Rencana Implementasi

> Dokumen ini adalah instruksi untuk agent yang akan **mengimplementasikan** WahaBuddy. Baca seluruh dokumen sebelum menulis kode. Hasil akhir adalah aplikasi open source yang berjalan, diuji, mudah dikonfigurasi, dan mudah di-deploy; bukan sekadar mockup atau scaffold.
>
> Revisi referensi: 31 Oktober 2026. Bahasa spesifikasi: Indonesia. UI, nama kode, dan dokumentasi repository: English. Isi pesan/caption bebas mengikuti input pengguna.

## A. Cara bekerja untuk agent implementasi

1. Baca `AGENTS.md`, kode, `composer.json`, dan `package.json` jika sudah ada. Ikuti konvensi proyek dan jangan menimpa pekerjaan pengguna. Jika repository kosong, bootstrap sesuai bagian C.
2. **Wajib menggunakan MCP Laravel Boost dan Laravel skill** selama implementasi, bukan sekadar memasangnya. Temukan tool berdasarkan katalog tool aktual; jangan mengarang nama tool. Setelah bootstrap, jalankan `composer require laravel/boost --dev` dan `php artisan boost:install`, pilih integrasi agent serta skills yang relevan, lalu sambungkan server MCP lokal dengan `php artisan boost:mcp` sesuai konfigurasi client. Pastikan versi terpasang mendukung command tersebut.
3. Melalui Boost, periksa informasi aplikasi/dependency dan cari dokumentasi yang sesuai versi sebelum mengerjakan Laravel HTTP client, scheduler, queue, storage, auth, migrasi, dan Inertia. Aktifkan Laravel guidelines/skill serta skills Inertia React, testing, dan Tailwind yang benar-benar tersedia. Baca file `SKILL.md` aktual; jangan mengklaim skill yang tidak ada telah digunakan.
4. **Wajib mencoba MCP Context7**: resolve library ID dahulu, kemudian query dokumentasi WAHA, Laravel/Inertia, atau shadcn sesuai kebutuhan. Jangan mengirim token, password, endpoint privat, atau data kontak ke query. Bila WAHA tidak terindeks, gunakan dokumentasi WAHA resmi dan OpenAPI instance pengguna.
5. Jika Context7 gagal, catat kegagalannya dan gunakan sumber resmi. Jika Boost/Laravel skill belum tersedia, perbaiki setup terlebih dahulu; jika tetap tidak bisa, laporkan hambatan sebelum melanjutkan implementasi domain. Jangan diam-diam menghapus kewajiban ini atau mengklaim tool berhasil.
6. Simpan keputusan versi dan hasil pemeriksaan endpoint di `docs/implementation-context.md`: versi, sumber, tanggal, tools yang benar-benar digunakan, keterbatasan, dan alasan penyimpangan. Jangan menyimpan rahasia atau data pribadi.
7. Implementasikan task pada bagian O berurutan. Untuk perilaku domain, tulis tes gagal, jalankan, implementasikan minimal, lalu verifikasi. Jangan melakukan live send ke WhatsApp tanpa target dan izin pengujian yang jelas; tes otomatis menggunakan fake.
8. Jangan meminta pengguna mengulangi keputusan yang sudah ada di dokumen ini. Putuskan detail kecil mengikuti konvensi Laravel, catat asumsi yang material, dan tanyakan hanya jika ada hambatan yang mengubah cakupan.

Catatan riset dokumen ini: Context7 telah dicoba tetapi mengembalikan `Unknown tool`; dokumentasi resmi dan Swagger instance referensi diperiksa langsung. MCP Boost tidak tersedia pada sesi penyuntingan dokumen dan belum dipasang karena aplikasi tidak diimplementasikan pada sesi tersebut. Agent implementasi tetap wajib mengikuti langkah di atas.

## B. Produk, batasan, dan tahapan rilis

Bangun **WahaBuddy**, panel self-hosted untuk menjadwalkan WhatsApp Status/Story dan menjalankan broadcast melalui WAHA self-hosted milik pengguna.

- Satu instalasi, satu administrator, satu koneksi WAHA aktif, satu session terpilih. Tidak ada SaaS multi-tenant, billing, atau registrasi publik.
- Milestone 1: login, setup koneksi, media, story scheduler, dashboard, audit log, deployment, dan tes.
- Milestone 2: broadcast text/image yang lengkap dengan kontak, recipient tracking, pacing, pause/resume/cancel, dan tes. **Wajib untuk rilis v1**, bukan “jika masih ada waktu”.
- Sesudah kedua milestone lolos, barulah v1 selesai. Kalender, recurring story, voice status, inbox/chatbot, multi-session bersamaan, webhook delivery/read analytics, dan konversi video lokal adalah fitur lanjutan di luar v1.
- WAHA mengelola koneksi WhatsApp dan pairing. WahaBuddy memilih session yang sudah ada; tidak wajib membuat atau me-restart session.
- Kiriman yang diterima WAHA berarti `sent`, bukan bukti semua penerima melihatnya. Status WhatsApp umumnya hilang setelah 24 jam; `expired` dalam aplikasi berarti jadwal terlalu lama untuk dikirim, bukan masa tayang berakhir.
- Broadcast hanya untuk penerima yang diizinkan pengguna. Jeda kirim tidak menjamin akun bebas pemblokiran.

### Keputusan arsitektur

Gunakan monolit Laravel + Inertia React. Ini sesuai stack yang diminta dan memungkinkan satu repository, auth session, serta deployment sederhana. Frontend terpisah dengan API token menambah deployment/auth tanpa kebutuhan v1. Redis, object storage wajib, dan SSR wajib juga menambah layanan; jadikan Redis/S3/R2 opsional, SSR nonaktif secara default.

Bagian D–N adalah spesifikasi produk. Bagian O adalah rencana pelaksanaan atas spesifikasi tersebut. Tidak perlu membangun aplikasi lain atau menyusun ulang cakupan dari nol.

## C. Stack dan bootstrap yang konsisten

| Bagian      | Keputusan                                                                                              |
| ----------- | ------------------------------------------------------------------------------------------------------ |
| Backend     | Laravel 13.x, PHP 8.3+; konfirmasi constraint dependency terpasang sebelum bootstrap                   |
| Frontend    | Official Laravel React Starter Kit, React 19, TypeScript, Inertia.js versi kompatibel dari starter kit |
| Inertia     | Referensi starter kit saat revisi memakai Inertia 3; paket PHP dan React harus major yang sama         |
| UI          | shadcn/ui + Tailwind CSS 4 + lucide-react + sonner                                                     |
| Auth        | Auth bawaan starter kit (Fortify), registrasi publik dinonaktifkan                                     |
| Form        | Inertia form helpers sebagai default; FormRequest sebagai validasi otoritatif                          |
| Tabel       | TanStack Table dengan pola shadcn dan pagination server                                                |
| Database    | SQLite default; MySQL 8+ didukung dengan migrasi yang sama                                             |
| Queue/cache | Database queue dan database cache/locks default; Redis opsional                                        |
| Media       | S3-only (S3-compatible/R2) dengan presigned URL; local disk tidak didukung                             |
| Waktu       | Persistensi UTC; timezone tampilan default Asia/Jakarta, dapat diubah                                  |
| SSR         | Nonaktif; Node dibutuhkan untuk build, tidak perlu proses SSR di production                            |
| Tes         | PHPUnit bawaan starter kit; HTTP/Storage/Queue fake, tes waktu dan concurrency yang relevan            |

Gunakan official starter kit, **jangan** menjalankan Breeze installer lama lalu menambahkan starter kit lain. Pastikan alias `@/*` menuju `resources/js/*`. Jangan menjalankan `shadcn init` ulang jika starter kit sudah menyediakannya. Ikuti bundler/script resmi starter kit yang dipilih; jangan mengganti tooling hanya agar cocok dengan prompt lama.

Gunakan installer Laravel yang kompatibel untuk memilih React starter kit, auth Laravel, SQLite, dan PHPUnit. Bekukan hasil dengan `composer.lock` serta lockfile npm. Catat versi aktual, PHP/Node minimum, dan perintah bootstrap yang telah diuji. Jangan mengartikan “latest” sebagai izin mencampur major atau dependency prerelease. Jika repo ternyata sudah menggunakan Laravel 12/Inertia 2 yang kompatibel, pertahankan versi itu dan dokumentasikan; jangan melakukan upgrade besar tanpa kebutuhan.

## D. Sumber kebenaran dan kontrak WAHA

Sumber resmi yang harus diperiksa:

- Introduction: https://waha.devlike.pro/docs/overview/introduction/
- Status: https://waha.devlike.pro/docs/how-to/status/
- Sessions: https://waha.devlike.pro/docs/how-to/sessions/
- Send messages: https://waha.devlike.pro/docs/how-to/send-messages/
- Contacts: https://waha.devlike.pro/docs/how-to/contacts/
- Chats: https://waha.devlike.pro/docs/how-to/chats/
- Engines/capabilities: https://waha.devlike.pro/docs/how-to/engines/
- Security: https://waha.devlike.pro/docs/how-to/security/
- OpenAPI: https://waha.devlike.pro/docs/how-to/swagger/
- Laravel starter kit: https://laravel.com/docs/starter-kits
- Laravel Boost: https://laravel.com/docs/boost
- shadcn: https://ui.shadcn.com/docs
- R2: https://developers.cloudflare.com/r2/api/s3/api/

Instance `https://waha.example.com/` adalah **referensi riset saja** (placeholder), tidak boleh menjadi default aplikasi, fixture, seed, `.env.example`, atau syarat deployment. Detail kontrak instance privat — versi, lokasi schema — disimpan di luar repository. Ini bukti kontrak yang dipublikasikan, **bukan** verifikasi semua endpoint saat runtime atau identifikasi engine session pengguna.

Jangan mengasumsikan lokasi OpenAPI sama untuk semua instalasi. Temukan dari Swagger UI instance target. Utamakan kontrak versi target dan dokumentasi resmi yang sesuai. Jika berbeda, catat perbedaannya dan tambahkan fixture/test adapter; jangan melakukan fallback POST ke endpoint lain setelah kiriman mungkin sudah diterima.

### D1. Autentikasi dan connection test

- Request WAHA hanya dari backend Laravel; browser tidak menerima API key.
- API memakai header `X-Api-Key`. HTTP Basic Auth untuk Swagger/dashboard berbeda dari API key; login dashboard bukan bukti API bisa digunakan.
- Sediakan optional `basic_username`/`basic_password` untuk reverse proxy yang memang melindungi API juga. Default kosong. Jangan hardcode kredensial riset.
- Base URL adalah origin plus optional path prefix, tanpa trailing slash; normalisasi satu kali. Session harus di-URL-encode sebagai satu path segment.
- `GET /api/sessions` untuk daftar session; `GET /api/sessions/{session}` untuk connection test. Hanya `status === "WORKING"` yang membolehkan send.
- Tampilkan nama session, status, informasi `me` yang tersedia, dan waktu test. Informasi engine/version dapat tidak tersedia; tampilkan `unknown`, jangan menebak.
- 401: autentikasi ditolak. 403: akses ditolak, bukan selalu session salah. 404: endpoint/session tidak ditemukan. Pisahkan respons reverse proxy dari API sejauh dapat diketahui.
- Session tidak WORKING atau konfigurasi belum lengkap: tahan pengiriman, tampilkan alasan, jangan habiskan attempt counter.

### D2. Endpoint yang dipakai v1

| Aksi            | Method dan path                           | Payload inti                                                               |
| --------------- | ----------------------------------------- | -------------------------------------------------------------------------- |
| Text story      | POST `/api/{session}/status/text`         | `text`, `backgroundColor`, `font`                                          |
| Image story     | POST `/api/{session}/status/image`        | `file: {mimetype, url}`, `caption` opsional                                |
| Video story     | POST `/api/{session}/status/video`        | `file: {mimetype, filename, url}`, `caption` opsional, `convert` eksplisit |
| Delete story    | POST `/api/{session}/status/delete`       | `id` menggunakan status message ID yang sesuai engine                      |
| Broadcast text  | POST `/api/sendText`                      | `session`, `chatId`, `text`                                                |
| Broadcast image | POST `/api/sendImage`                     | `session`, `chatId`, `file: {mimetype, url}`, `caption`                    |
| Contacts        | GET `/api/contacts/all?session={session}` | pagination mengikuti OpenAPI target                                        |
| Chats/groups    | GET `/api/{session}/chats`                | pagination mengikuti OpenAPI target; filter `@g.us`                        |

Contoh payload, nilai semuanya placeholder:

```json
{
    "file": {
        "mimetype": "video/mp4",
        "filename": "story.mp4",
        "url": "https://media.example.com/stories/example.mp4"
    },
    "caption": "Example caption",
    "convert": false
}
```

- Gunakan `convert: false` untuk MP4 H.264 yang memenuhi format WhatsApp. Ekstensi `.mp4` saja tidak membuktikan codec. Validasi codec via ffprobe bila tersedia; jika tidak, UI meminta pengguna menyiapkan format yang benar dan menjelaskan keterbatasannya. `convert: true` hanya tersedia jika capability konversi instance diverifikasi. Jangan menerima semua MOV/WEBM sebagai video yang pasti dapat dikirim.
- Response berbeda menurut engine. `SendResult` menyimpan `messageId` (top-level `id`) dan `keyId` (`key.id`) terpisah; keberhasilan dengan hanya salah satunya tetap valid. Untuk delete status, dokumentasi menunjukkan `key.id`; jangan mengganti dengan full ID secara sembarang. Bila ID delete tidak dapat ditentukan, nonaktifkan delete dengan alasan.
- Respons 2xx tanpa ID yang dikenal masuk `unknown`, bukan `sent` atau otomatis retry.
- Dukungan text/image/video, delete status, kontak, dan konversi bergantung versi/engine/edisi WAHA. Simpan capability sebagai `supported|unsupported|unknown` beserta sumbernya; ketiadaan capability discovery API bukan alasan mengarang endpoint. Gunakan matriks dokumentasi + versi/engine yang teridentifikasi + konfigurasi admin bila perlu. Jangan mencoba send sebagai probe capability.
- `GET /api/{session}/status/new-message-id` dan field `id` tersedia pada engine tertentu, tetapi **tidak membuktikan exactly-once delivery**. Penggunaan ID tetap harus diverifikasi, bukan dijadikan janji anti-duplikasi universal.

## E. Konfigurasi yang portabel

Infrastructure ada di `.env`; pengaturan operasional WAHA ada di database dan dapat diubah lewat Settings tanpa rebuild.

- `.env`: `APP_URL`, `APP_KEY`, database/cache/queue, timezone default, serta credentials `AWS_*` (wajib untuk upload media).
- DB singleton `waha_connections` menyimpan base URL, API key encrypted, optional Basic credentials encrypted, session, revision integer, capability metadata, dan hasil test terakhir yang sudah disanitasi.
- DB singleton `app_settings` menyimpan display timezone, delay broadcast min/max, batch size dan pause. Satu baris per instalasi, bukan tabel “single-row key-value” yang kontradiktif.
- Default koneksi kosong; session default `default` boleh sebagai saran, tetapi harus dipilih/divalidasi. Jangan isi host atau session privat.
- Optional `WAHA_BASE_URL`, `WAHA_API_KEY`, `WAHA_SESSION` hanya untuk import awal lewat command setup eksplisit; tidak diam-diam mengalahkan nilai DB. Jangan menaruh `env()` di service domain.
- Simpan secret dengan encrypted cast Laravel; backend hanya mengirim `has_api_key`/`has_basic_password` dan hint aman. Field secret kosong saat update berarti pertahankan secret lama; action clear terpisah. Jangan mengirim nilai lama kembali ke browser untuk reveal.
- Connection test menguji nilai form yang belum disimpan tanpa menyimpannya. Save tetap boleh saat WAHA offline dengan peringatan eksplisit; scheduler tetap menahan send sampai session valid. Test result terikat hash konfigurasi, tidak boleh menempel pada konfigurasi lain.
- Setiap perubahan host/key/session menaikkan `revision`; worker membaca konfigurasi per eksekusi, bukan singleton cache lintas job. Story/campaign menyimpan revision target saat dibuat/di-queue. Jika berubah, tahan job sampai admin secara eksplisit mengonfirmasi target baru. Jangan mengirim jadwal lama ke akun WA baru secara diam-diam.
- Perubahan timezone hanya mengubah tampilan dan input baru; tidak menggeser jadwal UTC yang sudah tersimpan.
- Koneksi HTTP untuk WAHA jaringan privat/Docker diperbolehkan melalui konfigurasi eksplisit; TLS verification tetap aktif untuk HTTPS. Batasi scheme ke HTTP/HTTPS, tolak userinfo/query/fragment dan URL metadata/link-local, jangan mengikuti redirect dengan credential ke host berbeda. Jangan memblokir seluruh alamat privat karena WAHA self-hosted sering berada di LAN.

Contoh `.env.example` yang harus dilengkapi agent dengan nilai default Laravel yang relevan:

```dotenv
APP_NAME=WahaBuddy
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_KEY=
APP_DISPLAY_TIMEZONE=Asia/Jakarta
DB_CONNECTION=sqlite
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=auto
AWS_BUCKET=
AWS_ENDPOINT=
AWS_URL=
AWS_USE_PATH_STYLE_ENDPOINT=false
```

## F. Model data dan invariants

Gunakan migrasi portable, foreign keys, indeks, PHP backed enums dengan kolom string, dan timestamps UTC. `id` bigint default Laravel; UUID hanya untuk attempt token dan nama file. Kolom nullable ditulis `?` di bawah. Buat juga tabel bawaan users, jobs, failed_jobs, cache, cache_locks, sessions.

| Tabel                | Kolom domain selain id/created_at/updated_at                                                                                                                                                                                                                                                                            |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| waha_connections     | base_url?, api_key?, basic_username?, basic_password?, session, revision default 1, capabilities JSON?, last_test_at?, last_test_status?, last_test_error?                                                                                                                                                              |
| app_settings         | display_timezone default Asia/Jakarta, broadcast_delay_min default 8, broadcast_delay_max default 20, broadcast_batch_size default 50, broadcast_batch_pause default 60                                                                                                                                                 |
| media_files          | disk, path, original_name, mime_type, size_bytes, video_codec?, created_by FK users                                                                                                                                                                                                                                     |
| stories              | type image/video/text, media_file_id? FK, text?, background_color default #38b42f, font default 1, scheduled_at, status, attempts default 0, next_attempt_at?, sending_started_at?, attempt_token?, connection_revision, waha_message_id?, waha_key_id?, last_error_code?, last_error?, sent_at?, deleted_from_waha_at? |
| story_logs           | story_id FK, event, attempt_token?, message, context JSON?; append-only sampai retention                                                                                                                                                                                                                                |
| contacts             | connection_revision, chat_id, name?, type contact/group, opted_in_at?, opted_out_at?                                                                                                                                                                                                                                    |
| broadcasts           | name, type text/image, media_file_id? FK, template_text, status, connection_revision, next_send_at?, batch_count default 0, started_at?, finished_at?                                                                                                                                                                   |
| broadcast_recipients | broadcast_id FK, chat_id, name?, rendered_text, status, attempts default 0, attempt_token?, sending_started_at?, next_attempt_at?, waha_message_id?, waha_key_id?, last_error_code?, last_error?, sent_at?                                                                                                              |
| broadcast_logs       | broadcast_id FK, recipient_id? FK, event, message, context JSON?                                                                                                                                                                                                                                                        |
| scheduler_runs       | started_at, finished_at?, status running/succeeded/failed, processed_count default 0, error_count default 0, last_error?                                                                                                                                                                                                |
| send_attempts        | attempt_token UUID unique, subject_type story/recipient, subject_id, connection_revision, started_at, finished_at?, outcome started/sent/rejected/unknown, http_status?, message_id?, key_id?, error_code?, error?                                                                                                      |

Indeks: stories `(status, scheduled_at)` dan `(status, next_attempt_at)`; recipients `(broadcast_id, status)`; broadcasts `(status, next_send_at)`; logs `(story_id, created_at)` atau `(broadcast_id, created_at)`; attempts `(subject_type, subject_id)`. Unique contacts `(connection_revision, chat_id)` dan recipients `(broadcast_id, chat_id)`.

Jangan simpan permanent public URL pada media sebagai sumber kebenaran; turunkan URL dari disk/path saat kirim. Jangan cascade menghapus media yang dipakai jadwal/campaign. Log dan attempt hanya menyimpan metadata tersanitasi, tanpa secret, raw payload berisi kontak, atau signed URL.

## G. Story scheduler dan keamanan pengiriman

### G1. State machine

```text
pending -> sending -> sent
sending -> failed             // penolakan yang terbukti tidak menerima kiriman
sending -> unknown            // hasil mungkin sudah diterima WAHA
failed -> sending             // hanya retry aman, saat next_attempt_at tiba
failed -> failed_max           // 3 send attempts telah habis
pending/failed -> expired      // jadwal lebih tua dari 7 hari
pending/failed/failed_max -> canceled
unknown -> sent               // rekonsiliasi atau konfirmasi admin beserta catatan
unknown -> pending            // admin mengakui risiko duplikasi, tercatat
failed_max -> pending          // admin membuka siklus retry baru, tercatat
```

`attempts` adalah jumlah HTTP send yang benar-benar dimulai dalam siklus saat ini, maksimum 3, termasuk attempt pertama. Manual retry memulai siklus baru; riwayat lama tetap di send_attempts/log. `sent` tidak boleh di-retry. Edit hanya pending/failed yang belum pernah berhasil dikirim. Cancel saat sending tidak dapat menarik kembali request yang sedang berjalan; UI menjelaskan hasil yang masih diproses.

### G2. Alur operasional

- Command `php artisan waha:send-stories` adalah dispatcher cepat, bukan loop HTTP panjang. Scheduler menjalankannya setiap menit dengan `withoutOverlapping()`. Cron tetap diperlukan untuk menjalankan `schedule:run`; Laravel Scheduler tidak menghilangkan kebutuhan pemicu OS.
- Pilih row due secara chunk, tanpa memuat seluruh tabel. Dispatch `SendStoryJob(storyId)` setelah transaksi commit. Queue job duplikat boleh ada; claim atomic yang menentukan siapa mengirim.
- Worker claim row melalui compare-and-swap status dan token dalam transaksi singkat. Simpan `sending`, UUID token, waktu mulai, increment attempts, serta send_attempts sebelum HTTP. Jangan menahan transaksi selama HTTP. Gunakan conditional update yang valid pada SQLite/MySQL, jangan hanya mengandalkan `lockForUpdate` pada SQLite.
- Ambil lock database/cache per koneksi WAHA sebelum HTTP; serialisasikan send story dan broadcast pada session yang sama. Jika lock sibuk, release job ke antrean tanpa menambah send attempt. Lease 300 detik, lebih lama dari timeout worker.
- Cek kembali status, revision, capability, media, dan WORKING setelah lock diperoleh. Item invalid ditahan dengan alasan; jangan membakar retry hanya karena konfigurasi offline.
- Sebelum kirim, bila send_attempts memiliki bukti ID sukses, pulihkan row ke sent tanpa request baru. Hanya worker dengan token aktif boleh menyelesaikan row; stale worker tidak boleh menimpa hasil attempt baru.
- Request timeout 150 detik, connect timeout 10 detik; job timeout 180, database queue `retry_after` 240, lock lease 300. Worker memakai `--timeout=180 --tries=1`; persistensi retry domain tidak diserahkan kepada retry otomatis queue.
- Jangan menggunakan `Http::retry()` untuk POST send/delete. Read-only GET boleh retry satu kali (dua total attempts) dengan backoff pendek. HTTP client exception, timeout, koneksi putus, 5xx setelah send, worker mati, atau response tak dikenal diperlakukan konservatif sebagai `unknown` jika penerimaan tidak dapat disangkal.
- Stale sending lebih dari 15 menit dipulihkan ke `unknown`, **tidak ke pending**. Tidak ada cara lokal menjamin exactly-once untuk HTTP non-idempotent ketika remote sudah sukses tetapi response/DB write hilang. Jangan menjanjikan sebaliknya.
- Penolakan eksplisit dan aman untuk retry mengikuti kontrak target: misalnya 429 yang jelas menolak request, jadwalkan ulang memakai Retry-After (detik atau HTTP-date), fallback 60 detik. Safe transient rejection memakai backoff 60 lalu 300 detik. 400/401/403/404/422 yang deterministik ditahan sampai pengguna memperbaiki penyebabnya, tidak auto-loop.
- Jika bukti penolakan tidak jelas, pilih unknown. Simpan code + ringkasan error tersanitasi maksimal 500 karakter; jangan menyalin raw response tanpa redaksi.
- Boundary jadwal: `scheduled_at <= now` due; tepat `now - 7 days` masih boleh; lebih tua expired. Semua perbandingan UTC. Waktu input harus ISO 8601 dengan offset atau dikonversi backend dari timezone pilihan; jangan menafsirkan datetime-local menggunakan timezone server secara kebetulan.
- Tombol “Run scheduler now” menjalankan dispatcher yang sama secara queued, authenticated, throttled. Jangan menunggu HTTP send dalam request UI.
- Setiap transisi menulis log. Catat heartbeat scheduler, heartbeat worker melalui job periodik, dan ringkasan kegagalan yang dapat dibaca dari dashboard. Prune story_logs ke 10.000 terbaru mingguan, dengan urutan `(created_at, id)` stabil; jangan menghapus bukti attempt kirim yang masih diperlukan unknown.

## H. Media dan storage

1. Media storage S3-only: WAHA mengunduh media sendiri, jadi local disk (yang butuh base URL publik/tunnel) tidak didukung. Upload ditolak dengan pesan jelas selama bucket `AWS_*` belum dikonfigurasi. Simpan `stories/YYYY/MM/{uuid}.{safe_extension}` melalui Laravel Storage pada disk `s3`. File lama peninggalan mode local disk tetap bisa dipreview lewat URL app dan tetap dibersihkan cleanup, tetapi tidak bisa dikirim lagi — upload ulang.
2. S3-compatible/R2 dikonfigurasi lewat `.env`. R2 memakai endpoint akun, region auto, serta presigned GET URL. Jangan mengandalkan ACL `public-read` yang belum tentu didukung R2; akses publik dikonfigurasi pada bucket/domain.
3. Presigned URL dibuat sesaat sebelum HTTP send, berlaku 15 menit, tidak disimpan/log. Bucket private + signed URL menjaga privasi media.
4. Maksimum 50 MiB (52.428.800 byte; Laravel max 51200 KiB), validasi ukuran serta MIME hasil deteksi server, bukan ekstensi dari browser. V1 menerima JPEG/PNG/WebP image serta MP4 video yang memenuhi format bagian D2. Format lain ditolak dengan pesan yang jelas; tidak perlu transcode lokal.
5. Media upload boleh langsung sebelum story dibuat; bersihkan orphan berumur >24 jam hanya jika tidak direferensikan story/campaign. Media yang dipakai unknown/sending tidak boleh dihapus.
6. Tombol Test Storage membuat object sementara, memeriksa URL dari backend dengan timeout singkat, lalu menghapusnya di finally. Jelaskan bahwa backend fetch sukses belum membuktikan reachability dari jaringan WAHA; uji end-to-end tetap diperlukan.
7. Jangan menerima arbitrary media URL dari pengguna dalam v1. URL untuk WAHA hanya dihasilkan dari disk/path terpercaya. Jangan mengirim cookie atau API key WAHA ketika menguji URL media.
8. Samakan batas PHP `upload_max_filesize`/`post_max_size` dan reverse proxy dengan batas aplikasi, dengan ruang overhead multipart. Jangan menjanjikan upload 50 MiB jika proxy menolak lebih dulu.

## I. Broadcast yang benar-benar berfungsi

- Wizard: nama -> text/image -> template `{name}` + preview -> pilih kontak/groups atau paste nomor -> review -> queue. Hanya `{name}` didukung v1; reject placeholder asing. Nama kosong fallback `there`; substitution sekali, bukan eval/recursive interpolation.
- Paste nomor wajib format internasional, optional `+`, tanpa menebak negara. Nomor lokal ditolak dengan penjelasan. ID grup WAHA dipertahankan; jangan mengubah `@g.us` menjadi nomor. Identifier lain seperti `@lid` jangan diparsing sebagai nomor; dukung hanya jika adapter target telah diuji, selain itu reject dengan jelas.
- Sync kontak dan chats paginated hingga selesai dengan timeout, batas aman, dan laporan partial failure. Sync tidak menganggap kontak otomatis opt-in; sertakan consent/opt-out dan izinkan admin mengonfirmasi izin penerima saat review. Snapshot chat_id, name, rendered_text, dan target revision ketika campaign di-queue.
- Deduplicate recipient setelah normalisasi. Unique constraint melindungi duplikasi request queue. Campaign tidak dapat di-queue jika tanpa recipient atau media tidak valid. Draft dapat diedit; payload campaign yang sudah berjalan tidak dapat diubah.
- Status campaign: draft, queued, running, paused, completed, canceled. Recipient: pending, sending, sent, failed, failed_max, unknown, canceled.
- Gunakan `ProcessBroadcastJob(broadcastId)` untuk memproses **satu recipient per eksekusi**. Jangan membuat seluruh recipient job dengan delay acak independen karena akan bertumpuk dan mengirim serentak.
- Claim campaign dan recipient atomic, lalu gunakan lock koneksi yang sama dengan story. Setelah send selesai, simpan `next_send_at = completion_time + random(8..20 seconds)` dengan batas inklusif dan gunakan delayed job berikutnya. Tambahkan guard `next_send_at` yang diperiksa ulang saat lock diperoleh agar job ganda tidak melewati pacing.
- Pacing berlaku antar broadcast di session yang sama: simpan timestamp sesi bersama di cache database di bawah lock; campaign lain juga wajib mematuhinya. Scheduler story mendapat prioritas ketika due; broadcast release singkat jika ada story due.
- Setelah setiap 50 HTTP send attempts broadcast, tambahkan pause minimal 60 detik dan reset batch_count; bukan sekadar setelah 50 sukses. Settings harus memvalidasi min>=1, max>=min, batch_size>=1, pause>=1. Nilai aktif dibaca saat penjadwalan berikutnya.
- Pause/cancel dicek ulang tepat sebelum request. Pause tidak menghapus antrean; job yang bangun saat paused no-op. Resume hanya dari paused, memakai guard yang sama. Cancel terminal dan menandai recipient belum dimulai canceled. Kiriman yang sudah berjalan mungkin tetap selesai dan tercatat sent.
- Terapkan klasifikasi unknown dan maksimal 3 safe attempts sama dengan story. Jangan auto-retry unknown. Unknown membuat campaign paused dengan alasan; setelah admin merekonsiliasi, resume eksplisit. Tambahkan sweeper job periodik untuk campaign runnable tanpa job lanjutan akibat crash setelah commit.
- Progress dihitung dari agregasi recipient agar tidak drift. Completed ketika tidak ada pending/sending/unknown; bisa completed dengan failures. UI menampilkan sent, failed/failed_max, pending, unknown, canceled terpisah. Gunakan polling ringan, tanpa websocket wajib.

## J. UI, route, dan tindakan

Layout responsive: sidebar Dashboard, Stories, Broadcasts, Contacts, Settings; top bar menampilkan connection status dan freshness, timezone, dark mode, user menu. Gunakan shadcn asli yang tersedia; `DataTable` adalah pola TanStack, bukan komponen paket terpisah, dan warna info/blue dibuat melalui class/token, bukan variant shadcn fiktif.

| Halaman           | Perilaku wajib                                                                                                                                                                                         |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Dashboard         | count stories today/sent month/pending/failed/unknown, jadwal terdekat, activity, scheduler/worker heartbeat dan error banner                                                                          |
| Stories           | pagination, filter tanggal/status/type, thumbnail, caption excerpt, jadwal absolut + timezone, badge, last error, detail log                                                                           |
| Story Create/Edit | type text/image/video, upload/progress/preview, caption max 500 karakter; text story max 700 sebagai batas produk; color #RRGGBB dan font integer dari opsi terverifikasi; waktu dan timezone terlihat |
| Story actions     | edit/cancel yang valid, manual retry, resolve unknown dengan konfirmasi risiko dan audit; delete remote terpisah dari record lokal                                                                     |
| Broadcasts        | wizard, preview, recipient counts, progress, pause/resume/cancel, list errors dan resolusi unknown                                                                                                     |
| Contacts          | sync, pagination/search, type contact/group, opt-in/opt-out                                                                                                                                            |
| Settings          | base URL, secret replacement, optional Basic auth, session list/manual, test unsaved config, save warning offline, capability, timezone, pacing, test storage                                          |

Routes authenticated di `routes/web.php` dengan FormRequest dan policy/authorization. Mutasi biasa via Inertia; upload/test koneksi/poll boleh JSON menggunakan session auth dan CSRF. Tidak perlu Sanctum/public API v1. Contoh route: `/stories`, `/broadcasts`, `/contacts`, `/settings`, POST `/settings/test-connection`, POST `/settings/test-storage`, POST `/scheduler/run`, POST `/stories/{story}/retry`, POST `/stories/{story}/resolve`, POST `/stories/{story}/delete-remote`, POST `/broadcasts/{broadcast}/pause|resume|cancel` (buat tiga route konkret).

Tampilkan empty state, skeleton, loading/disabled action, inline validation, toasts, accessible labels, keyboard focus, dan mobile Sheet. Jangan optimistic menganggap cancel/delete berhasil saat side effect remote belum pasti. Jangan membuat tautan “view on WhatsApp” fiktif tanpa deep-link yang terverifikasi.

## K. Auth, data, dan keamanan dasar

- Admin dibuat melalui command interaktif `php artisan wahabuddy:admin`; password diminta secara tersembunyi, di-hash, tanpa default, tanpa seed kredensial. Command aman dijalankan ulang tanpa reset password diam-diam.
- Nonaktifkan route/feature registrasi, bukan hanya menyembunyikan link. Terapkan auth, CSRF, login throttling, rate limit koneksi/test/upload/manual scheduler, dan authorization semua mutasi.
- Secret tidak boleh ada di Inertia props, logs, exceptions yang tampil, repository, `.env.example`, fixture, atau screenshot. API key disensor di HTTP debug. Jangan mengaktifkan verbose request/response body logging sebagai default.
- Escape user content dengan React; jangan render HTML dari caption/template/WAHA error.
- Pertahankan APP_KEY antar deploy. Backup key di lokasi aman karena diperlukan untuk decrypt WAHA credentials. Dokumentasikan backup/restore DB + media + key, tanpa memasukkan backup ke git.
- Production: HTTPS, secure session cookie, APP_DEBUG=false, webroot public/, tidak expose storage private/.env atau MCP dev server. Boost adalah dev dependency, bukan layanan publik.

## L. Deployment: dua jalur yang diuji

Sediakan README quick start dan `docs/deployment.md` yang dapat diikuti pengguna baru. WahaBuddy menghubungkan WAHA yang sudah ada; tidak wajib membundel WAHA atau lisensi WAHA Plus.

### L1. Native VPS

Dokumentasikan prerequisites beserta versi aktual; install Composer/npm dependencies, copy env, buat SQLite jika belum ada, generate APP_KEY **hanya instalasi pertama**, migrate, storage:link, build asset, buat admin, login, dan isi WAHA Settings. Jangan jalankan seeder yang mereset akun.

Production menjalankan:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan queue:restart
```

Worker di Supervisor/systemd: `php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=180`. Restart otomatis bila crash, shutdown grace >180 detik. Cron satu baris setiap menit menjalankan `php artisan schedule:run` dari direktori aplikasi sebagai user yang benar. Jelaskan izin storage/bootstrap/cache dan file SQLite. Jangan jalankan dua scheduler berbeda tanpa koordinasi.

### L2. Docker Compose

Buat multi-stage Dockerfile: frontend assets dibuild di build stage, runtime tanpa npm/Boost/dev tools. Compose minimal `web` (Nginx), `app` (PHP-FPM), `worker`, `scheduler` (`schedule:work`), dan service `init` satu kali. `init` selesai sukses sebelum tiga proses aplikasi mulai, menjalankan migrasi/storage link tanpa race; admin dibuat eksplisit melalui command.

Gunakan image aplikasi yang sama untuk app/worker/scheduler/init. Persist `/var/www/html/storage` dan direktori SQLite `/var/www/html/database` melalui named volumes bersama; bootstrap database kosong tanpa menimpa DB yang sudah ada. Nginx dapat membaca public media melalui volume read-only dan alias yang eksplisit. APP_KEY disediakan stabil lewat env/secret; jangan generate setiap container start. Tentukan user/ownership, healthchecks, restart policy, dan graceful stop.

Dokumentasikan `cp .env.example .env`, pengisian APP_URL/APP_KEY, `docker compose up -d --build`, command admin, log/health checks, update, backup/restore. Sertakan cara membuat APP_KEY sebelum startup. SQLite hanya untuk deployment single host dengan volume lokal; scale multi-host memakai MySQL + shared cache/locks + shared media.

Untuk MySQL, ubah DB_CONNECTION/host/database/username/password, siapkan server lalu migrate. Perubahan env tidak memindahkan data SQLite lama secara otomatis; dokumentasikan ekspor/impor jika diperlukan. R2/S3 dan Redis tetap opsional.

## M. Struktur file dan interface domain

Ikuti lowercase paths starter kit untuk frontend; jangan mencampur Pages/pages pada Linux.

```text
app/Services/Waha/{WahaClient,ConnectionRepository,CapabilityResolver}.php
app/Services/Waha/DTO/{ConnectionSnapshot,ConnectionCheck,SendResult}.php
app/Services/Waha/Exceptions/WahaRequestException.php
app/Actions/Stories/{ClaimStory,CompleteStory,ResolveStory}.php
app/Actions/Broadcasts/{QueueBroadcast,ClaimRecipient,ResolveRecipient}.php
app/Services/Media/MediaUrlResolver.php
app/Services/Sending/SessionSendGate.php
app/Jobs/{SendStoryJob,ProcessBroadcastJob,RecordWorkerHeartbeatJob}.php
app/Console/Commands/{DispatchDueStories,RecoverStaleSends,PruneStoryLogs,RecoverBroadcasts,CreateAdmin}.php
app/Models/ dan app/Enums/ sesuai tabel/state
app/Http/{Controllers,Requests}/ sesuai fitur
resources/js/pages/{dashboard,stories,broadcasts,contacts,settings}/
resources/js/components/{ui,stories,broadcasts,layout}/
resources/js/types/wahabuddy.ts
routes/{web,console}.php
config/wahabuddy.php
config/{filesystems,queue}.php
.env.example, Dockerfile, compose.yaml, docker/nginx.conf
README.md, LICENSE, CONTRIBUTING.md, docs/{deployment,implementation-context}.md
.github/workflows/ci.yml
```

Interface yang harus konsisten (namespace/DTO sesuai struktur di atas):

- `ConnectionRepository::current(): ConnectionSnapshot` — base URL, secret internal, session, revision, capabilities; DTO tidak diserialisasi ke Inertia.
- `WahaClient::checkConnection(ConnectionSnapshot $connection): ConnectionCheck` — status/session/me/capability terverifikasi, error tersanitasi; tanpa send.
- `WahaClient::sendStory(ConnectionSnapshot $connection, Story $story, ?string $mediaUrl): SendResult`.
- `WahaClient::sendBroadcast(ConnectionSnapshot $connection, BroadcastRecipient $recipient, Broadcast $broadcast, ?string $mediaUrl): SendResult`.
- `WahaClient::deleteStatus(ConnectionSnapshot $connection, string $keyId): void` — capability dan original target harus cocok; tidak otomatis retry.
- `SendResult` readonly DTO: `?string $messageId`, `?string $keyId`; paling sedikit satu wajib terisi, selain itu throw outcome unknown.
- `WahaRequestException` membawa `code`, `?int httpStatus`, `safeMessage`, outcome `rejected|unknown`, dan `retryable` yang hanya true untuk rejected yang terbukti aman.
- `MediaUrlResolver::forSend(MediaFile $media): string` — URL segar untuk request ini.
- `SessionSendGate` mengelola lock koneksi + timestamp pacing; expose `acquire(ConnectionSnapshot $connection): ?SendLease`. Tambahkan DTO `SendLease` pada `app/Services/Sending/SendLease.php` dengan `release(): void`; release selalu pada finally. Gate tidak menjalankan HTTP.
- `ClaimStory::handle(int $storyId, ConnectionSnapshot $connection): ?string` menghasilkan attempt UUID atau null jika tidak eligible; `CompleteStory::handle(int $storyId, string $attemptToken, SendResult $result): bool` conditional persistence + audit.
- `ResolveStory::handle(Story $story, string $decision, string $note): void`, decision `mark_sent|retry`; untuk mark_sent wajib bukti ID atau konfirmasi admin yang dicatat tanpa mengarang ID WAHA.
- `QueueBroadcast::handle(Broadcast $broadcast, array $recipients): void` snapshot+dedupe+dispatch after commit; `ClaimRecipient::handle(int $broadcastId, ConnectionSnapshot $connection): ?BroadcastRecipient` menyimpan attempt token sebelum return.
- `ResolveRecipient::handle(BroadcastRecipient $recipient, string $decision, string $note): void` mengikuti aturan unknown yang sama dengan story; campaign tetap paused sampai resume eksplisit.

Tambahkan unit kecil seperti error classifier atau template renderer bila membantu, dengan interface/test terdokumentasi. Jangan membangun generic repository framework atau workflow engine untuk tiga fitur ini.

## N. Definition of Done dan failure modes utama

V1 selesai hanya jika:

- Clone bersih dapat dijalankan melalui README dengan SQLite dan local storage, tanpa akun R2/Redis dan tanpa host pribadi.
- Login/admin creation aman, registrasi publik benar-benar tidak tersedia.
- User dapat mengganti WAHA base URL/key/session melalui Settings; secret tidak bocor dan perubahan target menahan pekerjaan lama sampai dikonfirmasi.
- Text/image/video story dapat dibuat, dijadwalkan, dikirim melalui adapter WAHA, dan dilihat jejak hasilnya. Live smoke test hanya dilakukan bila mendapat izin/target; jika belum, laporkan sebagai belum diverifikasi.
- Concurrent jobs tidak mengirim row yang sama dua kali. Timeout/crash menjadi unknown tanpa automatic resend. Jangan menyebut exactly-once terjamin.
- Safe retry maksimum 3 attempts, expiry boundary, UTC conversion, stale recovery, orphan cleanup, dan retention teruji.
- Broadcast v1 benar-benar mengirim text/image, menjaga pacing antar campaign, batch pause, pause/resume/cancel, serta recipient tracking.
- Fitur WAHA unsupported/unknown ditampilkan jelas, tidak disimulasikan sukses.
- CI SQLite dan MySQL hijau; frontend typecheck/build, PHP tests/lint hijau; Docker Compose boot/restart/persistence telah diuji.
- README/deployment/security notes tersedia, license MIT dan CONTRIBUTING tanpa rahasia. Laporkan command yang benar-benar dijalankan dan cek yang belum dapat dilakukan.

Lima failure modes wajib punya test pada task pemiliknya: konfigurasi berubah saat job tertunda; WAHA sukses tetapi response/worker hilang; worker paralel pada SQLite; timezone offset/input browser berbeda; pause/cancel saat job sudah ada di queue.

## O. WahaBuddy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Jika pengguna memilih delegasi, gunakan superpowers:subagent-driven-development. Langkah menggunakan checkbox untuk tracking. Dokumen ini tidak memerintahkan implementasi pada sesi penyuntingan prompt; instruksi berlaku ketika pengguna menjalankan build prompt.

**Goal:** Menghasilkan WahaBuddy v1 yang mengirim scheduled story dan broadcast melalui WAHA pengguna, dengan konfigurasi/deployment sederhana serta pengelolaan hasil kirim ambigu.

**Architecture:** Monolit Laravel + Inertia React dengan database queue dan atomic claims. HTTP non-idempotent dipisahkan dari transaksi; media local default dengan S3/R2 opsional.

**Tech Stack:** Laravel 13.x/PHP 8.3+, React 19/TypeScript/Inertia kompatibel starter kit, shadcn/ui/Tailwind 4, SQLite/MySQL, PHPUnit, Laravel Boost MCP dan skills.

**Spec:** Bagian A–N dalam dokumen ini; salin spesifikasi ke `docs/wahabuddy-spec.md` saat implementasi supaya ikut repository.

### Global Constraints

- Satu administrator, satu koneksi/session aktif; registrasi publik nonaktif.
- Secret encrypted di DB; tidak ada hardcoded host/key/kredensial pribadi.
- UTC persistence; display timezone default Asia/Jakarta.
- Send HTTP timeout 150s, job 180s, queue retry_after 240s, lock 300s; stale sending >15 menit menjadi unknown.
- Maksimal 3 safe send attempts per siklus; tidak ada automatic retry POST ambigu.
- Broadcast delay 8–20s inklusif, batch 50 attempts, pause minimal 60s.
- Upload maksimum 50 MiB; local media default dan R2 opsional.

### Review Focus

- Revision koneksi berubah: job lama tertahan sampai target dikonfirmasi — Task 2/5/7.
- Remote sukses tetapi response/proses hilang: unknown dan tidak auto-resend — Task 3/5/7.
- Worker concurrent dengan SQLite: hanya satu claim/token/send yang efektif — Task 5/7.
- Browser/server timezone berbeda: instant jadwal tetap benar dan expiry boundary konsisten — Task 4/5.
- Pause/cancel setelah enqueue: penerima belum dimulai tidak dikirim — Task 7.

Setiap task di bawah berakhir dengan tes fokus dan commit kecil; jangan commit jika pengguna melarang git commit. Untuk kode domain, jalankan test yang baru dibuat sebelum implementasi dan pastikan gagal karena perilaku belum ada, lalu jalankan ulang setelah implementasi. Jangan membuat tes dangkal hanya untuk memenuhi checklist.

### Task 1 — Foundation, admin, dan tooling

**Files:** starter kit, `CreateAdmin.php`, `config/fortify.php`, `routes/web.php`, `tests/Feature/AdminAccessTest.php`, `docs/implementation-context.md`.
**Interfaces:** menghasilkan auth session dan shell Inertia untuk seluruh fitur.

- [ ] Bootstrap stack bagian C, setup Boost MCP/skills dan Context7 sesuai A; catat dependency/tool aktual.
- [ ] Tambahkan test `guest_cannot_access_settings`, `public_registration_is_disabled`, `admin_command_does_not_reset_existing_password`; assert guest redirect ke login, registration route tidak tersedia, hash lama tetap sama pada run ulang tanpa action reset eksplisit.
- [ ] Jalankan `php artisan test --filter=AdminAccessTest`, implementasikan admin command/auth restriction, jalankan ulang hingga PASS.
- [ ] Buat shell responsive dan dark mode; jalankan `npm run build` serta typecheck. Gunakan script aktual starter kit; tambahkan alias `npm run typecheck` untuk `tsc --noEmit` bila belum ada.
- [ ] Commit foundation yang terverifikasi.

### Task 2 — Settings, encrypted connection, capability

**Files:** migrations/models koneksi/settings, `ConnectionRepository.php`, DTO koneksi/check, `CapabilityResolver.php`, Settings controller/FormRequests/page, `tests/Feature/SettingsTest.php`.
**Interfaces:** menghasilkan `current()` dan konfigurasi revision bagian M; memakai shell Task 1.

- [ ] Test `secrets_are_encrypted_and_never_in_props`, `blank_secret_preserves_value`, `test_does_not_persist_unsaved_form`, `revision_changes_hold_old_work`, `private_http_requires_explicit_configuration`; assert ciphertext != input dan output tidak mengandung input, revision naik hanya ketika target berubah.
- [ ] Jalankan test hingga gagal, implementasikan storage/validation/settings dan contract capability tri-state; test koneksi memakai fake adapter sementara sampai Task 3 selesai.
- [ ] Verifikasi `php artisan test --filter=SettingsTest` PASS dan frontend typecheck PASS; commit.

### Task 3 — WAHA HTTP adapter

**Files:** `WahaClient.php`, DTO `SendResult`, `WahaRequestException.php`, `tests/Unit/WahaClientTest.php`, fixture tersanitasi per engine di `tests/Fixtures/waha/`.
**Interfaces:** semua method WahaClient/SendResult/exception di M; consumes ConnectionSnapshot Task 2.

- [ ] Dengan `Http::preventStrayRequests()` + fake, test exact method/path/header/payload untuk tiga story, broadcast text/image, dan delete; assert video mengirim convert eksplisit dan chats memakai path session.
- [ ] Test `accepts_top_level_id_or_key_id`, `two_xx_without_id_is_unknown`, `timeout_and_ambiguous_five_xx_are_not_retried`, `forbidden_is_not_assumed_missing_session`, `unsupported_delete_is_blocked`; assert hanya satu POST saat timeout dan error tidak memuat secret.
- [ ] Jalankan `php artisan test --filter=WahaClientTest` sampai failure yang diharapkan; implementasikan adapter tanpa retry POST, sambungkan Settings test ke adapter aktual.
- [ ] Jalankan WahaClientTest dan SettingsTest hingga PASS; commit.

### Task 4 — Media dan story CRUD

**Files:** media/story migrations/models/enums, `MediaUrlResolver.php`, controllers/FormRequests/pages stories, `tests/Feature/MediaTest.php`, `tests/Feature/StoryCrudTest.php`.
**Interfaces:** menghasilkan Story/MediaFile dan media URL bagian M untuk send jobs.

- [ ] Test file MIME mismatch, >50 MiB, format unsupported, referenced media deletion, orphan cleanup, local/signed URL resolution tanpa credential leak.
- [ ] Test CRUD auth/validation, text 700/caption 500, immutable sent story, serta input `2026-11-01T09:00:00+07:00` disimpan `2026-11-01T02:00:00Z` dan ditampilkan kembali 09:00 WIB.
- [ ] Jalankan kedua test sebelum implementasi; implementasikan media dan form/list; tambahkan test storage cleanup saat fetch gagal.
- [ ] Jalankan `php artisan test --filter='MediaTest|StoryCrudTest'`, typecheck/build hingga PASS; commit.

### Task 5 — Durable send pipeline dan scheduler

**Files:** story actions, `SendStoryJob.php`, `SessionSendGate.php`, `SendLease.php`, commands dispatcher/recovery/prune, migrations send_attempts/scheduler_runs, `routes/console.php`, `tests/Feature/StorySchedulerTest.php`, `tests/Integration/StoryConcurrencyTest.php`.
**Interfaces:** consumes Task 2–4; produces claim/complete/resolve dan shared send gate bagian M untuk broadcast.

- [ ] Test due/future/exact seven-day boundary, 3-attempt cap, safe backoff, credential failure hold, revision hold dan existing proof auto-heal tanpa HTTP.
- [ ] Test stale sending menjadi unknown, timeout tidak auto-retry, worker crash sesudah fake remote accept, token lama tidak boleh menimpa row, serta resolve unknown wajib note/konfirmasi.
- [ ] Buat concurrency integration test memakai dua proses dan SQLite file bersama; assert hanya satu claim menang dan hanya pemenang boleh mengeksekusi send. Jalankan juga pada MySQL; jangan memakai in-memory SQLite terpisah sebagai bukti concurrency.
- [ ] Jalankan test sampai gagal; implementasikan atomic claim/attempt persistence, short dispatcher, lock, jobs, retry classifier, UTC logic dan recovery.
- [ ] Verifikasi test fokus PASS, `php artisan schedule:list` menunjukkan dispatcher/maintenance/heartbeat, `php artisan waha:send-stories` quiet jika kosong; commit.

### Task 6 — Dashboard dan operasional story

**Files:** dashboard controller/page, story detail/actions UI, heartbeat job, `tests/Feature/DashboardTest.php`, browser smoke script jika tooling tersedia.
**Interfaces:** consumes state/log/health Task 5; produces admin visibility dan tindakan recovery.

- [ ] Test dashboard counts berdasarkan timezone pilihan, unknown ditampilkan terpisah, heartbeat stale terlihat, unauthorized manual trigger ditolak dan authorized trigger dispatch job tanpa HTTP sinkron.
- [ ] Jalankan test gagal; implementasikan dashboard/actions/log pagination serta warning capability/target revision.
- [ ] Jalankan `php artisan test --filter=DashboardTest`, typecheck/build; periksa mobile/keyboard/loading/error states melalui browser jika tersedia dan catat hasil; commit.

### Task 7 — Contacts dan broadcast engine

**Files:** broadcast/contact migrations/models/enums, `QueueBroadcast.php`, `ClaimRecipient.php`, `ResolveRecipient.php`, `ProcessBroadcastJob.php`, `RecoverBroadcasts.php`, contacts sync service, `tests/Feature/ContactsTest.php`, `tests/Feature/BroadcastTest.php`, `tests/Integration/BroadcastConcurrencyTest.php`.
**Interfaces:** consumes shared gate/adapter/media Task 2–5; produces wizard-ready campaign actions bagian I/M.

- [ ] Test pagination sync, normalized dedupe, local number rejection, group IDs preserved, opt-out excluded, snapshot template/name, `{name}` fallback there, unsupported placeholder rejected.
- [ ] Test two campaigns pada session sama, interval minimum setelah completion, rentang RNG 8–20, batch pause sesudah 50 attempts, repeated queue request tidak menggandakan recipients, revision mismatch holds.
- [ ] Test pause/cancel setelah enqueue menghasilkan nol POST untuk recipient belum dimulai; resume hanya paused; stale/timeout unknown pauses campaign; crash setelah persistence sebelum dispatch berikutnya dipulihkan sweeper; terminal counts tetap konsisten.
- [ ] Jalankan test gagal; implementasikan satu recipient per job dengan guard/token dan conditional completion. Concurrency integration dua proses membuktikan recipient tunggal serta pacing session tidak dilanggar pada SQLite/MySQL.
- [ ] Jalankan `php artisan test --filter='ContactsTest|BroadcastTest|BroadcastConcurrencyTest'` hingga PASS; commit.

### Task 8 — Broadcast/contacts UI

**Files:** controllers/FormRequests/routes/pages/components broadcasts dan contacts, `tests/Feature/BroadcastPagesTest.php`.
**Interfaces:** consumes Task 7; route UI mengikuti J.

- [ ] Test unauthorized action, queue draft invalid, campaign payload immutable setelah queue, snapshot preview dan progress per state; test resolve recipient tidak auto-resume campaign.
- [ ] Jalankan test gagal; implementasikan wizard, polling, pause/resume/cancel, errors, resolusi unknown, sync contacts dan izin recipient review.
- [ ] Jalankan `php artisan test --filter=BroadcastPagesTest`, typecheck/build hingga PASS; browser smoke wizard text/image, duplicate recipients dan paused campaign; commit.

### Task 9 — Packaging, CI, deployment docs

**Files:** Dockerfile/compose/nginx, `.env.example`, README/LICENSE/CONTRIBUTING, docs deployment, `.github/workflows/ci.yml`.
**Interfaces:** menghasilkan clean-install native/Docker sesuai L atas aplikasi Task 1–8.

- [ ] Tambahkan CI test matrix SQLite/MySQL, PHP lint dan frontend typecheck/build dengan lockfiles; seluruh outbound WAHA fake.
- [ ] Buat Docker/README dengan init satu kali, stable APP_KEY, volumes bersama, cron/worker instructions, storage network reachability dan backup/restore.
- [ ] Jalankan `docker compose config`, build/up clean volume untuk test, admin creation, login/health, worker+scheduled heartbeat, restart dan cek DB/media/key tetap utuh; jangan menghapus volume pengguna saat pengujian.
- [ ] Jalankan suite penuh `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm run build`; semua harus PASS. Periksa nama script aktual dan sediakan alias dokumentasi yang konsisten.
- [ ] Audit repository untuk secret/private host dan file env/backup ter-track; commit artifact deployment yang sudah terverifikasi.

### Task 10 — Final acceptance dan handoff

**Files:** `docs/implementation-context.md`, `docs/deployment.md`, perbaikan kecil yang ditemukan acceptance.
**Interfaces:** tidak menambah fitur baru; memverifikasi Definition of Done N.

- [ ] Review setiap requirement A–N dan petakan ke implementasi/test; perbaiki gap sebelum menyebut v1 selesai.
- [ ] Jika diizinkan, gunakan session/target test pengguna untuk satu text/image/video story dan broadcast kecil. Verifikasi pada WhatsApp, catat versi/engine dan hasil tersanitasi; jangan memakai penerima produksi tanpa izin.
- [ ] Bila live WAHA tidak tersedia, pastikan suite fake tetap lulus dan nyatakan integrasi live belum diverifikasi. Bedakan lulus test adapter dengan berhasil live send.
- [ ] Handoff berisi fitur selesai, command/hasil test aktual, cara menjalankan, cara mengganti WAHA URL/key/session, cara deploy/update/restore, serta keterbatasan nyata. Jangan menyatakan selesai jika broadcast masih scaffold atau deployment belum diuji.

---

Akhir build prompt. Utamakan perilaku yang dapat diuji dan dokumentasi yang dapat diikuti pengguna baru. Jangan mengganti ketidakpastian remote dengan klaim keberhasilan atau retry otomatis yang berisiko menduplikasi kiriman.
