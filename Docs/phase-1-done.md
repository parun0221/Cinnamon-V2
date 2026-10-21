# Phase 1 — Foundation dan Autentikasi: Completion Notes

Status: **complete** (verified 2026-10-21).

## Yang dibangun

| Area | Hasil |
|---|---|
| Backend | `POST /api/v1/me/password` — pengguna mengganti passwordnya sendiri. `POST/GET /api/v1/users`, `GET/PATCH /api/v1/users/{user}` — pengelolaan user oleh Admin. `UserPolicy::create()`. Provider `active` yang mengakhiri akses akun yang dinonaktifkan. |
| Frontend | Navigasi modul per role (7 grup), halaman 404, `PageState` (loading/empty/error), halaman Profil dengan form ganti password, halaman Pengguna untuk Admin, sidebar/topbar, menu mobile di bawah 720px. |
| Docs | `Docs/setup.md` — panduan instalasi dari database kosong. Decision §10 (`Docs/decisions.md`) — verifikasi lewat aplikasi yang jalan, tanpa file test baru. |

## Perubahan yang terlihat pengguna

- **Profil** punya form "Ganti password" kedua: tiga input password, pesan error server menempel pada field yang salah, nilai yang diketik tidak hilang saat gagal, tombol nonaktif saat request berjalan.
- **Sidebar** menampilkan tujuh grup modul (Dashboard, Master Data, Penerimaan, Penjualan, Inventori, Laporan, Administrasi). Modul yang halamannya belum ada tetap tampil sebagai tautan dan membuka halaman 404 berbahasa Indonesia dengan tombol "Kembali ke Dashboard".
- **Karyawan** tidak melihat entri "Pengguna", dan mengetik `/admin/users` memberi halaman "Akses tidak diizinkan", bukan daftar user.
- **Halaman Pengguna** (Admin) menampilkan nama, email, peran, dan status tiap akun, dengan kendali ubah peran dan aktif/nonaktifkan. Menonaktifkan akun langsung kehilangan aksesnya.
- **Menu mobile** di bawah 720px: sidebar tertutup dan dibuka lewat tombol "Menu" yang memperbarui `aria-expanded`.
- **Akun yang dinonaktifkan** kehilangan akses seketika, termasuk session yang sedang berjalan — sebelumnya hanya login berikutnya yang tertolak.

## Verifikasi yang dijalankan

Semua dijalankan terhadap server yang benar-benar hidup (`php artisan serve` di
127.0.0.1:8000 dan Vite dev server di 5173) memakai database dev `cinnamon_v2`.
Tidak ada file test baru — suite yang ada dipakai sebagai jaring regresi.

### API — ganti password (`POST /api/v1/me/password`)

| # | Pemeriksaan | Hasil | Cara memverifikasi |
|---|---|---|---|
| A | `current_password` salah | 422 `VALIDATION_ERROR`, `fields.current_password` = "Password saat ini salah." | `curl` dengan session admin |
| B | `password` hanya 7 karakter | 422, `fields.password` = "password harus minimal 8 karakter." | `curl` |
| C | `password_confirmation` tidak sama | 422, `fields.password` + `fields.password_confirmation` | `curl` |
| D | Semua benar | 200, `{"data":null,"meta":{request_id}}` | `curl` |
| E | Login dengan password baru | 200 | `curl` |
| F | Login dengan password lama | 401 `UNAUTHENTICATED` | `curl` |
| G | Request tanpa session | 401 `UNAUTHENTICATED` | `curl` tanpa cookie |

### API — pengelolaan user

| # | Pemeriksaan | Hasil | Cara memverifikasi |
|---|---|---|---|
| A | Karyawan `GET /users` | 403 `FORBIDDEN`, `meta.request_id` ada | `curl` dengan session karyawan |
| B | `POST /users` dengan email ganda | 422, `fields.email` = "Email ini sudah terdaftar." | `curl` |
| C | `POST /users` role `karyawan` | 201, `data.role` = "karyawan" | `curl` |
| D | `POST /users` tanpa `password` | 422, `fields.password` = "password wajib diisi." | `curl` |
| E | `GET /users/{id}` user lain | 200 | `curl` |
| F | `PATCH is_active:false` | 200, `data.is_active` = false | `curl` |
| G | User nonaktif mencoba login | 401 `UNAUTHENTICATED` | `curl` |
| H | Session lama user nonaktif | 401 `UNAUTHENTICATED` | `curl` memakai cookie lama |
| I | Karyawan `GET /users/{id}` | 403 `FORBIDDEN` | `curl` |
| J | `GET /users/{uuid tidak ada}` | 404 `NOT_FOUND` | `curl` |
| K | `PATCH role:"admin"` | 200, `data.role` = "admin" | `curl` |
| L | Admin menonaktifkan dirinya sendiri | 422, `fields.is_active` = "Akun sendiri tidak dapat dinonaktifkan." | `curl` |

### Browser — navigasi, 404, dan form (headless Chrome lewat CDP)

| # | Pemeriksaan | Hasil | Cara memverifikasi |
|---|---|---|---|
| A | Admin membuka `/` | Sidebar menampilkan 7 grup modul | Chrome headless |
| B | Sidebar Admin | Memuat tautan "Pengguna" | Chrome headless |
| C | Admin membuka `/admin/users` | 2 baris seed tampil | Chrome headless |
| D | Admin mengubah peran | Perubahan bertahan setelah refresh | Chrome headless |
| E | Karyawan membuka `/` | Modul "Pengguna" tidak tampil | Chrome headless |
| E2 | Karyawan | "Profil" tetap terjangkau (dibutuhkan untuk ganti password) | Chrome headless |
| F | Karyawan mengetik `/admin/users` | Halaman "Akses tidak diizinkan", tanpa tabel | Chrome headless |
| G | `/halaman-ngawur` | 404 Indonesia + tombol "Kembali ke Dashboard" → `/` | Chrome headless |
| G2 | Karyawan di `/halaman-ngawur` | 404 yang sama | Chrome headless |
| H | `/profile` | 2 form; 3 input bertipe `password` | Chrome headless |
| I | Password lama salah | "Password saat ini salah." pada field; input tidak dikosongkan | Chrome headless |
| J | Password lama benar | "Password berhasil diganti."; field dikosongkan | Chrome headless |
| K | Logout lalu login dengan password baru | Berhasil, path `/` | Chrome headless |
| L | Login dengan password lama | Ditolak, "Email atau password salah." | Chrome headless |
| M | Password demo dikembalikan | "Password berhasil diganti." | Chrome headless |
| N | Jendela 480px | Tombol menu tampil, sidebar tertutup | Chrome headless |
| O | Klik tombol menu | Sidebar terbuka, `aria-expanded="true"` | Chrome headless |
| P | Console browser | 0 error, 0 warning, 0 exception | Chrome headless |

### Pemeriksaan statis dan jaring regresi

| Pemeriksaan | Hasil |
|---|---|
| `backend/./vendor/bin/pint --test` | passed, tanpa offense |
| `backend/php artisan test` | 47 passed (210 assertions) |
| `frontend/npx tsc -b` | bersih |
| `frontend/npm run lint` | bersih |
| `frontend/npx prettier --check src` | semua file sesuai gaya Prettier |
| `frontend/npm run build` | sukses, menghasilkan `dist/` |
| `frontend/npm test` | 22 passed |
| `cinnamon_v2.users` setelah verifikasi | 2 baris seed, `is_active` = 1, password = `password` |

## Keputusan yang diambil saat pengerjaan

1. **`current_password` perlu pesan Bahasa Indonesia.** Rule bawaan Laravel tidak
   punya terjemahan di `lang/id/validation.php`, sehingga skenario A akan
   menjawab dalam bahasa Inggris. Ditambahkan baris rule dan nama atributnya.
2. **Menonaktifkan akun harus mengakhiri akses yang sedang berjalan.**
   Provider bawaan Laravel mencari user dari session berdasarkan id saja,
   tanpa filter `is_active`, sehingga akun yang dinonaktifkan tetap bisa
   memakai session lama. Ditambahkan `App\Auth\ActiveUserProvider` yang
   memfilter `is_active` pada `retrieveById` dan `retrieveByToken`.
3. **Menu "Pengguna" disembunyikan dari Karyawan, bukan seluruh grup
   "Administrasi".** `Docs/Design.md` §3 menempatkan Profil di dalam grup
   Administrasi, dan Karyawan butuh Profil untuk mengganti password.
4. **Tombol menu mobile sempat tidak pernah tampil.** Aturan `display: none`
   ada tanpa media query yang menampilkannya, jadi sidebar tidak punya cara
   terbuka di layar sempit.
5. **Prettier memakai `endOfLine: "auto"`.** Nilai bawaan `lf` menulis ulang
   seluruh file ber-`CRLF` dan menghasilkan diff ratusan baris pada file yang
   tidak disentuh.

## Belum dikerjakan

- **Modul operasional masih kosong.** Master data, penerimaan, penjualan,
  inventori, dan laporan baru berupa tautan ke halaman 404. Itu Phase 2–3.
- **Halaman pengguna belum bisa menambah akun.** Endpoint `POST /users` sudah
  ada dan terverifikasi, tapi belum ada form-nya di UI.
- **Validasi `per_page`/`page` masih longgar.** Nilai di luar rentang tidak
  menjawab `INVALID_FILTER`.
- **Rate limiter `api` belum dipakai** pada endpoint baca.
- **Checkbox "ingat saya" belum ada** di layar login, walau backend sudah
  menyediakannya.
- **Ukuran `post_max_size`/`memory_limit` PHP** belum diatur; baru relevan
  saat ada upload gambar produk (Phase 2.1).

## Cara menjalankan lokal

Ringkas:

```bash
# backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve                       # http://localhost:8000

# frontend
cd frontend
npm install
npm run dev                             # http://localhost:5173
```

Panduan lengkap, termasuk masalah umum, ada di `Docs/setup.md`.
