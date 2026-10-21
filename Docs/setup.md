# Setup — CINNAMON Inventory dan Sales Management

Panduan menjalankan CINNAMON di mesin lokal. Proyek berisi dua aplikasi: `backend/`
(Laravel 13, REST API) dan `frontend/` (React 19 + TypeScript + Vite).

## 1. Prasyarat

| Kebutuhan | Versi | Catatan |
|---|---|---|
| PHP | 8.5 (minimal 8.3) | `composer.json` mensyaratkan `php: ^8.3` |
| Composer | 2.x | Mengpasang dependensi PHP |
| Node.js | 20.x atau lebih baru | Vite 8 dan tooling frontend |
| MySQL | 8+ (atau MariaDB kompatibel) | XAMPP sudah memuat MySQL 8 |
| Ekstensi PHP | `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`, `dom` | `pdo_mysql` wajib untuk database |

Cek ekstensi yang aktif:

```bash
php -m
```

Ekstensi `intl` **tidak** diperlukan dan memang tidak terpasang di proyek ini.
Backend memakai `number_format()`, bukan `Illuminate\Support\Number::format()`,
yang butuh `intl`.

## 2. Database

Buat database baru untuk schema CINNAMON:

```sql
CREATE DATABASE cinnamon_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

> **Peringatan.** Database `cinnamon` berisi 19 tabel legacy beserta fixture TA
> (data `produks`, `produk_masuks`, `buku_besars`, `batch_stoks`). Database itu
> **tidak boleh dihapus, dimigrasi, atau dipakai** untuk schema baru. Ia
> disimpan sebagai contoh penerimaan untuk Phase 2–3. Schema CINNAMON hanya
> boleh hidup di `cinnamon_v2`.

Kredensial lokal default (XAMPP): host `127.0.0.1`, port `3306`, user `root`,
password kosong. Kredensial sebenarnya tetap berada di `.env` lokal.

## 3. Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

`php artisan serve` berjalan di `http://localhost:8000`.

- `key:generate` mengisi `APP_KEY`. Nilai ini **tidak boleh** masuk commit —
  `.env` sudah ada di `.gitignore`.
- `migrate --seed` membuat tabel dan mengisi dua akun demo.
- `php artisan serve` centralised pada mode `APP_ENV=local`. Jangan memakai
  `php artisan serve` di produksi.

Cek cepat:

```bash
curl http://127.0.0.1:8000/api/v1/health
# {"data":{"status":"ok"},"meta":{"request_id":"…"}}
```

## 4. Frontend

```bash
cd frontend
npm install
npm run dev
```

Vite berjalan di `http://localhost:5173` dan mem-proxy `/api` dan `/sanctum` ke
`http://localhost:8000`. Buka `http://localhost:5173/login`.

> **Jangan** mengisi `VITE_API_URL` dengan URL absolut saat pengembangan.
> Cookie session Sanctum harus tetap first-party; lewat Vite proxy, frontend dan
> API berbagi origin sehingga cookie itu bekerja. `VITE_API_URL` hanya diisi
> bila frontend disajikan dari domain lain.

## 5. Akun demo

| Email | Password | Peran | Boleh |
|---|---|---|---|
| `admin@cinnamon.test` | `password` | Admin | Semua modul, termasuk pengelolaan pengguna, laporan, dan stok |
| `karyawan@cinnamon.test` | `password` | Karyawan | Pencatatan penerimaan dan kerja produk pending saja |

Akun dibuat oleh `backend/database/seeders/DatabaseSeeder.php`. Seed sintetis — tidak
ada data pribadi.

Mengganti password salah satu akun mengubahnya di database. Untuk mengembalikan
password demo ke `password`:

```bash
cd backend
php artisan tinker --execute="App\Models\User::where('email','karyawan@cinnamon.test')->first()->forceFill(['password'=>'password'])->save();"
```

## 6. Environment

### Backend (`backend/.env`)

| Variabel | Nilai default | Keterangan |
|---|---|---|
| `APP_KEY` | diisi `key:generate` | Kunci enkripsi cookie dan password. **Rahasia.** |
| `APP_URL` | `http://localhost:8000` | URL dasar aplikasi |
| `APP_LOCALE` | `id` | Bahasa pesan validasi |
| `DB_*` | `cinnamon_v2` di `127.0.0.1:3306` | Koneksi MySQL |
| `SESSION_DRIVER` | `database` | Session disimpan di tabel `sessions` |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:5173,…` | Origin yang boleh memakai session cookie |

### Frontend (`frontend/.env`)

| Variabel | Nilai default | Keterangan |
|---|---|---|
| `VITE_API_URL` | kosong | Kosongkan saat dev (Vite proxy). Isi hanya bila frontend disajikan dari origin lain. |

Tidak ada variabel frontend yang boleh berisi rahasia. `VITE_*` masuk ke bundle
yang dikirim ke browser.

## 7. Pemeriksaan

```bash
# backend
cd backend
./vendor/bin/pint --test          # format
php artisan test                  # suite yang ada (47 test)

# frontend
cd frontend
npx tsc -b                        # type check
npm run lint                      # ESLint
npm run format:check              # Prettier
npm run build                     # production build
npm test                          # suite yang ada (22 test)
```

Suite yang ada dipakai sebagai jaring regresi dan **tidak** diperluas. Fitur
baru diverifikasi dengan menjalankan aplikasinya — lihat `Docs/decisions.md` §10.

## 8. Masalah umum

**Login selalu 403 "Permintaan login harus berasal dari origin yang diizinkan."**
Request tidak dianggap stateful, jadi Sanctum tidak memulai session. Penyebab:
frontend diakses lewat origin yang tidak ada di `SANCTUM_STATEFUL_DOMAINS`,
atau `VITE_API_URL` diisi URL absolut. Pastikan dev memakai Vite proxy.

**Semua request API 419 "Sesi Anda telah berakhir."**
Token CSRF tidak cocok atau cookie sudah kedaluwarsa. Muat ulang halaman. Di
`VITE_API_URL` yang absolut, cookie Sanctum sering tidak terkirim karena
`SameSite` dan domain cookie.

**"419" muncul terus-menerus setelah `php artisan serve` dijalankan ulang.**
Session disimpan di database, tetapi cookie `XSRF-TOKEN` di browser masih milik
server sebelumnya. Muat ulang atau hapus cookie situs.

**Login gagal dengan 429.**
Limiter login mengizinkan 5 percobaan per menit per kombinasi IP dan email.
Tunggu sebentar.

**Request dengan body besar ditolak sebelum Laravel menjawab.**
`post_max_size` dan `memory_limit` PHP terlalu kecil. Naikkan di `php.ini` —
`post_max_size` harus lebih besar dari ukuran body yang dikirim.

**MySQL menolak koneksi.**
MySQL XAMPP belum berjalan, atau port 3306 dipakai proses lain. Periksa
layanan MySQL di XAMPP Control Panel.

**Tabel `users` complains "kolom id bertipe bigint".**
Aplikasi berjalan di schema legacy, bukan `cinnamon_v2`. Perbaiki `DB_DATABASE`
di `.env`, lalu jalankan `php artisan migrate:fresh --seed` pada database
`cinnamon_v2` — **jangan pernah** pada `cinnamon`.
