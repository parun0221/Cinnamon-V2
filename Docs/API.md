# API Contract

## 1. Ringkasan

Dokumen ini menetapkan kontrak API untuk CINNAMON, sistem inventory dan sales Toko Wahana Eka Sentral. Kontrak ini mengikuti alur bisnis pada laporan TA dan README proyek awal: penerimaan barang, pemisahan stok pending dan ready, verifikasi penerimaan, penjualan dengan FIFO, koreksi stok, laporan, dan ekspor.

**Target implementasi:** Laravel 13 + PHP 8.5 sebagai backend API, React TypeScript sebagai client web, dan Flutter sebagai client mobile opsional pada fase berikutnya.

**Status:** kontrak MVP yang dapat ditinjau. Perubahan endpoint atau field harus dicatat sebagai keputusan perubahan kontrak.

## 2. Batasan dan Konvensi Umum

- Base URL: `/api/v1`.
- Transport: HTTPS pada lingkungan staging/production.
- Format: `application/json` untuk request dan response, kecuali ekspor file.
- Tanggal: ISO 8601, contoh `2026-10-18` atau `2026-10-18T09:30:00+07:00`.
- ID: PK increment yang dipilih konsisten pada implementasi; kontrak tidak mengasumsikan integer berurutan.
- Nilai uang dan berat dikirim sebagai string desimal untuk mencegah pembulatan client, contoh `"12500.00"`.
- Response menyertakan `request_id` agar error dapat ditelusuri pada log.
- Operasi yang mengubah stok wajib mendukung header `Idempotency-Key`.
- Endpoint MVP hanya mencakup satu toko. Multiwarehouse, marketplace, payment gateway, dan integrasi eksternal menjadi fase terpisah.

### 2.1 Envelope response

Response sukses tunggal:

```json
{
  "data": {},
  "meta": { "request_id": "req_01..." }
}
```

Response koleksi:

```json
{
  "data": [],
  "meta": {
    "request_id": "req_01...",
    "current_page": 1,
    "per_page": 20,
    "last_page": 4,
    "total": 73
  }
}
```

Response error:

```json
{
  "error": {
    "code": "INSUFFICIENT_STOCK",
    "message": "Stok ready produk tidak mencukupi.",
    "fields": {},
    "details": {}
  },
  "meta": { "request_id": "req_01..." }
}
```

## 3. Authentication dan Authorization

React SPA menggunakan cookie session Sanctum. Sebelum login, client mengambil CSRF cookie dari endpoint Laravel berikut:

`GET /sanctum/csrf-cookie`

Endpoint tersebut berada di luar prefix `/api/v1` karena merupakan mekanisme Sanctum.

| Endpoint | Method | Akses | Tujuan |
|---|---:|---|---|
| `/auth/login` | POST | Publik | Membuat session operator |
| `/auth/logout` | POST | Authenticated | Mengakhiri session |
| `/me` | GET | Authenticated | Mengambil user aktif dan role |
| `/me` | PATCH | Authenticated | Memperbarui profil yang diizinkan |
| `/me/password` | POST | Authenticated | Mengganti password |

Role `admin` dapat mengelola master data, user, approval penerimaan, penjualan, koreksi stok, ledger, dan seluruh laporan. Role `karyawan` hanya menjalankan pencatatan penerimaan dan pengelolaan produk pending/not-ready sesuai alur. Policy server tetap menjadi sumber kebenaran; client tidak boleh menentukan hak akses sendiri.

### 3.1 Login request

```json
{
  "email": "operator@example.test",
  "password": "secret-password",
  "remember": true
}
```

Response sukses mengembalikan ringkasan user, bukan password atau token rahasia.

## 4. Katalog Endpoint MVP

### 4.1 Master data

| Endpoint | Method | Role | Tujuan |
|---|---:|---|---|
| `/categories` | GET | Semua auth | Daftar kategori |
| `/categories` | POST | Admin | Membuat kategori |
| `/categories/{category}` | GET | Semua auth | Detail kategori |
| `/categories/{category}` | PATCH | Admin | Mengubah kategori |
| `/categories/{category}` | DELETE | Admin | Menghapus jika belum dipakai |
| `/products` | GET | Semua auth | Daftar produk dengan stok |
| `/products` | POST | Admin | Membuat produk |
| `/products/{product}` | GET | Semua auth | Detail produk dan batch ringkas |
| `/products/{product}` | PATCH | Admin | Mengubah atribut master |
| `/products/{product}` | DELETE | Admin | Soft delete jika aman |
| `/suppliers` | GET | Admin/Karyawan | Memilih supplier pada penerimaan |
| `/suppliers` | POST | Admin | Membuat supplier |
| `/suppliers/{supplier}` | GET/PATCH/DELETE | Admin | Mengelola supplier |
| `/customers` | GET | Admin | Data customer opsional |
| `/customers` | POST | Admin | Membuat customer |
| `/customers/{customer}` | GET/PATCH/DELETE | Admin | Mengelola customer dan riwayat dasar |
| `/users` | GET/POST | Admin | Pengelolaan user |
| `/users/{user}` | GET/PATCH | Admin | Mengubah role/status user |

Query produk yang didukung: `search`, `category_id`, `status`, `stock_status`, `sort`, `page`, `per_page`. Nilai `sort` hanya boleh berasal dari allowlist server.

### 4.2 Penerimaan dan verifikasi barang

| Endpoint | Method | Role | Tujuan |
|---|---:|---|---|
| `/incoming-receipts` | GET | Authenticated | Riwayat penerimaan dengan filter |
| `/incoming-receipts` | POST | Karyawan/Admin | Membuat draft penerimaan |
| `/incoming-receipts/{receipt}` | GET | Authenticated | Detail penerimaan |
| `/incoming-receipts/{receipt}` | PATCH | Pemilik draft/Admin | Mengubah draft |
| `/inventory/pending-products` | GET | Admin/Karyawan | Daftar item pending/not-ready |
| `/inventory/pending-products/{item}` | PATCH | Admin/Karyawan | Mengubah berat/status pending sebelum approval |
| `/incoming-receipts/{receipt}/verify` | POST | Admin | Menyetujui atau menolak hasil verifikasi |
| `/incoming-receipts/{receipt}/cancel` | POST | Admin | Membatalkan dokumen sesuai aturan |

Penerimaan yang disetujui membentuk batch stok, menambah stok ready atau pending sesuai status barang, dan membuat stock movement. Approval harus atomik: semua efek berhasil bersama-sama atau tidak ada yang disimpan.

### 4.3 Inventory dan koreksi

| Endpoint | Method | Role | Tujuan |
|---|---:|---|---|
| `/inventory/stock` | GET | Admin | Saldo ready/pending dan ringkasan batch |
| `/inventory/movements` | GET | Admin | Riwayat pergerakan stok |
| `/inventory/adjustments` | GET | Admin | Riwayat koreksi |
| `/inventory/adjustments` | POST | Admin | Membuat draft koreksi |
| `/inventory/adjustments/{adjustment}` | GET/PATCH | Admin | Meninjau draft koreksi |
| `/inventory/adjustments/{adjustment}/post` | POST | Admin | Mem-posting koreksi |
| `/inventory/adjustments/{adjustment}/cancel` | POST | Admin | Membatalkan draft |

Filter inventory: `product_id`, `movement_type`, `date_from`, `date_to`, `period`, `search`, `page`, `per_page`.

### 4.4 Penjualan dan FIFO

| Endpoint | Method | Role | Tujuan |
|---|---:|---|---|
| `/sales` | GET | Admin | Riwayat penjualan |
| `/sales` | POST | Admin | Membuat draft penjualan |
| `/sales/{sale}` | GET | Admin | Detail penjualan dan alokasi batch |
| `/sales/{sale}` | PATCH | Admin | Mengubah draft |
| `/sales/{sale}/post` | POST | Admin | Mem-posting penjualan |
| `/sales/{sale}/cancel` | POST | Admin | Membatalkan transaksi sesuai aturan |

Saat `post`, server mengambil batch ready tertua terlebih dahulu, dapat membagi satu item ke beberapa batch, menghitung HPP dari alokasi aktual, mengurangi stok, dan menyimpan `sale_allocations`. Jika stok tidak cukup, transaksi ditolak tanpa perubahan parsial.

### 4.5 Dashboard, laporan, dan ekspor

| Endpoint | Method | Role | Tujuan |
|---|---:|---|---|
| `/dashboard/summary` | GET | Admin | KPI stok, penjualan, pending, dan profit periode |
| `/reports/incoming` | GET | Admin | Laporan penerimaan |
| `/reports/outgoing` | GET | Admin | Laporan penjualan, HPP, dan profit |
| `/reports/stock` | GET | Admin | Laporan saldo stok |
| `/reports/movements` | GET | Admin | Laporan pergerakan stok |
| `/reports/ledger` | GET | Admin | Buku ledger sederhana |
| `/reports/{report}/export` | GET | Admin | Ekspor CSV/XLSX setelah format disetujui |

Semua laporan menerima `date_from`, `date_to`, `product_id`, `category_id`, `status`, `page`, dan `per_page` hanya bila relevan. Ekspor adalah stream/download file dan wajib memakai filter yang sama dengan tampilan laporan.

## 5. Request contract inti

### 5.1 Membuat draft penerimaan

`POST /api/v1/incoming-receipts`

```json
{
  "supplier_id": "sup_01...",
  "receipt_date": "2026-10-18",
  "note": "Penerimaan periode 16-akhir bulan",
  "items": [
    {
      "product_id": "prd_01...",
      "weight_initial": "25.000",
      "weight_final": "24.500",
      "unit_cost": "42000.00",
      "is_pending": false,
      "note": null
    }
  ]
}
```

Validasi minimal: tanggal valid, item minimal satu, produk aktif, berat final tidak negatif dan tidak melebihi aturan bisnis, unit cost tidak negatif, dan supplier valid bila diisi.

### 5.2 Verifikasi penerimaan

`POST /api/v1/incoming-receipts/{receipt}/verify`

```json
{
  "decision": "approved",
  "reason": "Jumlah dan kualitas telah diverifikasi."
}
```

`decision` hanya `approved` atau `rejected`. Approval membuat batch dan movement; rejection wajib menyimpan alasan dan tidak menambah stok.

### 5.3 Membuat dan mem-posting penjualan

`POST /api/v1/sales`

```json
{
  "customer_id": null,
  "sale_date": "2026-10-18",
  "note": null,
  "items": [
    {
      "product_id": "prd_01...",
      "quantity": "2.000",
      "unit_price": "55000.00"
    }
  ]
}
```

Posting dilakukan melalui `POST /api/v1/sales/{sale}/post` dengan body opsional:

```json
{
  "confirmation": true
}
```

Response detail penjualan yang sudah diposting wajib menyertakan subtotal, total, HPP, gross profit, dan alokasi FIFO per item.

### 5.4 Koreksi stok

`POST /api/v1/inventory/adjustments`

```json
{
  "adjustment_date": "2026-10-18",
  "reason": "Selisih hasil stock opname",
  "items": [
    {
      "product_id": "prd_01...",
      "quantity_delta": "-0.500",
      "stock_bucket": "ready"
    }
  ]
}
```

Koreksi harus mencatat alasan, user pembuat, user pem-posting, dan movement yang dihasilkan.

## 6. Filtering, pagination, dan sorting

- Default `per_page`: 20; maksimum: 100.
- Pagination menggunakan `page` dan `per_page`.
- Pencarian memakai `search` pada field yang relevan saja.
- Sorting memakai format `sort=field` atau `sort=-field`; field harus ada di allowlist endpoint.
- Rentang tanggal inklusif dan menggunakan timezone aplikasi.
- Filter yang tidak dikenal menghasilkan `INVALID_FILTER`, bukan diabaikan diam-diam.

## 7. Status HTTP dan kode error

| HTTP | Code | Makna |
|---:|---|---|
| 400 | `BAD_REQUEST` | Format perintah tidak dapat diproses |
| 401 | `UNAUTHENTICATED` | Session tidak valid |
| 403 | `FORBIDDEN` | Policy role menolak aksi |
| 404 | `NOT_FOUND` | Resource tidak ditemukan atau tidak terlihat |
| 409 | `RECEIPT_ALREADY_APPROVED` | Dokumen sudah diverifikasi |
| 409 | `DOCUMENT_ALREADY_POSTED` | Dokumen tidak boleh diposting ulang |
| 409 | `INSUFFICIENT_STOCK` | Stok ready tidak cukup |
| 409 | `IDEMPOTENCY_CONFLICT` | Key sama dipakai untuk payload berbeda |
| 422 | `VALIDATION_ERROR` | Field request tidak valid |
| 429 | `RATE_LIMITED` | Terlalu banyak request |
| 500 | `SERVER_ERROR` | Kegagalan tak terduga; detail tidak dibocorkan |

Error validasi menyertakan `fields` per nama field. Error bisnis tidak boleh ditutupi sebagai error validasi biasa.

## 8. Idempotensi, transaksi, dan konkurensi

- `Idempotency-Key` wajib untuk `verify`, `post`, `cancel`, dan `adjustment/post`.
- Server menyimpan hasil response untuk key yang sama selama periode retensi yang ditetapkan.
- Approval penerimaan, posting penjualan, dan posting koreksi berjalan dalam database transaction.
- Batch yang dipakai FIFO dikunci saat perhitungan dan pengurangan stok.
- Dua request posting bersamaan tidak boleh menghasilkan stok negatif atau alokasi ganda.
- Retry aman hanya bila key dan payload sama.

## 9. Integrasi eksternal dan webhook

Tidak ada integrasi eksternal yang wajib untuk MVP. Aplikasi hanya membutuhkan database, filesystem storage untuk gambar/logo, queue/cache bila dipakai untuk ekspor, serta email opsional untuk reset password.

Payment gateway, marketplace, barcode provider, dan webhook pihak ketiga ditunda. Jika kelak diperlukan, integrasi harus berada di service adapter backend; credential tidak pernah dikirim ke browser atau mobile client.

## 10. Rate limit dan keamanan

- Login dan reset password memiliki limit lebih ketat daripada endpoint baca.
- Endpoint laporan dan ekspor memiliki limit per user untuk mencegah beban database.
- Semua endpoint dilindungi session/auth dan CSRF sesuai mode Sanctum.
- Validasi ownership dan policy dilakukan di server.
- Field sensitif seperti password, session, dan secret tidak pernah masuk response atau log biasa.
- Production hanya menerima HTTPS dan nilai environment disimpan di secret manager/host configuration.

## 11. Environment contract

Backend membutuhkan sekurang-kurangnya `APP_URL`, `APP_KEY`, `APP_ENV`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, session/Sanctum/CORS configuration, serta konfigurasi storage/cache/queue bila diaktifkan.

Frontend hanya membutuhkan `VITE_API_URL` dan konfigurasi non-rahasia lain yang memang boleh terlihat di browser. Tidak ada database password, signing key, atau API secret di environment frontend.

## 12. Kriteria penerimaan API

- User dapat login, melihat role, dan logout.
- Admin dapat mengelola kategori, produk, supplier, customer, dan user sesuai policy.
- Karyawan dapat membuat draft penerimaan; Admin dapat memverifikasi dan hasilnya tercermin pada batch, saldo, dan movement.
- Posting penjualan selalu memakai FIFO, menghitung HPP/profit, dan menolak stok tidak cukup tanpa perubahan parsial.
- Koreksi stok selalu memiliki alasan dan audit trail.
- Laporan menampilkan angka yang sama dengan saldo dan movement yang tersimpan.
- Pagination, filter, error envelope, request ID, dan idempotency berjalan konsisten pada endpoint yang relevan.
- Kontrak dapat dikonsumsi React dan tidak membutuhkan rahasia di client.

## 13. Di luar kontrak MVP

Fitur berikut memerlukan keputusan produk dan kontrak baru: multiwarehouse/transfer, pembelian dengan pembayaran, full accounting, pajak, supplier portal, customer portal, marketplace sync, barcode hardware, mobile offline sync, dan notifikasi real-time eksternal.

