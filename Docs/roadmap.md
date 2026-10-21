# Roadmap CINNAMON

## Tujuan

Roadmap ini menyusun lima dokumen produk CINNAMON menjadi urutan kerja yang dapat dipecah menjadi implementation plan per fase. CINNAMON adalah modular monolith untuk satu toko: Laravel API, React TypeScript web client, dan MySQL. MVP memakai role Admin dan Karyawan.

## Dokumen acuan:

- `Docs/PRD.md`: tujuan, pengguna, alur, scope, dan acceptance produk.
- `Docs/Architecture.md`: stack dan batas komponen.
- `Docs/Database.md`: tabel, relasi, konsistensi stok, dan histori.
- `Docs/API.md`: endpoint dan kontrak komunikasi.
- `Docs/Design.md`: bahasa antarmuka dan sistem visual.

Roadmap menjelaskan urutan serta batas fase. Skill penulisan plan yang dipilih pengguna dapat membuat rincian task, file, dependency, verifikasi, dan checklist dari fase yang dipilih.

## Cara mengerjakan

Gunakan satu repository dengan folder backend, frontend, dan Docs. Kerjakan setiap fitur sebagai vertical slice dengan pendekatan contract-first:

1. Sepakati alur pengguna, aturan domain, dan API contract.
2. Implementasikan backend Laravel untuk validasi, izin, transaksi, dan hasil otoritatif.
3. Sambungkan React pada endpoint yang sudah stabil.
4. Tinjau keadaan loading, kosong, gagal, konflik, berhasil, dan responsive.
5. Jalankan pemeriksaan fase dan lanjut hanya jika acceptance criteria terpenuhi.

Rekomendasi urutan: backend dahulu untuk aturan data dan API dalam setiap fitur, lalu frontend terintegrasi segera setelah contract siap. Kerangka layout dan komponen presentasi boleh dibuat paralel setelah API contract ditetapkan. Hindari membangun seluruh backend dan seluruh frontend secara terpisah sampai akhir.

## Phase 0 — Keputusan produk dan contoh penerimaan

**Tujuan:** menyelesaikan keputusan yang dapat mengubah schema, alur stok, atau angka laporan sebelum coding yang bergantung padanya.

**Ruang lingkup:**

- Konfirmasi aturan identitas batch: satu batch per item penerimaan atau gabungan produk dalam periode tanggal 1–15 dan 16–akhir bulan.
- Definisikan kapan quantity berada di pending dan kapan menjadi ready, termasuk hubungan berat awal, berat final, status item, approval, dan rejection.
- Pilih format ID konsisten untuk seluruh tabel dan API.
- Putuskan apakah supplier wajib pada penerimaan.
- Definisikan arti debit, kredit, dan balance untuk ledger operasional.
- Siapkan contoh penerimaan, approval, penjualan lintas batch, HPP, profit, koreksi, dan laporan sebagai acceptance fixtures.

**Selesai jika:** keputusan bisnis yang diperlukan untuk Phase 1–3 tercatat atau ditandai eksplisit sebagai tertunda dengan fase yang diblokir. Tidak ada aturan stok atau laporan yang disimpulkan diam-diam dari skema.

## Phase 1 — Foundation dan autentikasi

**Tujuan:** menyiapkan dua aplikasi yang dapat dijalankan lokal dan terhubung melalui API yang disepakati.

**Ruang lingkup:**

- Buat struktur repository backend Laravel dan frontend React TypeScript + Vite.
- Konfigurasi MySQL, environment lokal, Sanctum session cookie, CSRF, CORS, dan timezone Asia/Jakarta.
- Siapkan role Admin/Karyawan, login, logout, profile/current user, policy awal, dan demo seeder sintetis.
- Tetapkan API response/error envelope, request id, health endpoint, dan normalisasi error.
- Siapkan React router, API client, auth provider, protected routes, app shell, token Design.md, dan layar login.
- Siapkan lint, formatter, test runner, build, serta instruksi menjalankan kedua aplikasi.

**Selesai jika:** instalasi bersih dapat migrate dan seed; health endpoint merespons; Admin/Karyawan dapat login dan memanggil profile; route dan API menolak akses yang tidak berhak; React menampilkan loading/error dengan benar; tidak ada secret di frontend atau repository.

**Ketergantungan:** Phase 0 menetapkan format ID dan auth/role assumptions yang mempengaruhi migration/API. UI shell boleh berjalan paralel setelah API auth contract disepakati.

## Phase 2 — Inventory core

### Phase 2.1 — Katalog dan parties

**Tujuan:** menyiapkan master data yang dibutuhkan penerimaan dan penjualan.

**Ruang lingkup:**

- CRUD kategori, produk, dan supplier; customer bersifat opsional.
- SKU unik, kategori, satuan, harga referensi, harga jual, status aktif, minimum stock, serta projection ready/pending sesuai Database.md.
- Search, filter, sort allowlist, pagination, validasi, dan permission Admin/Karyawan.
- Referensi yang sudah dipakai dinonaktifkan, bukan dihapus.
- Gambar produk ditambahkan setelah storage dan validasi file siap.

**Selesai jika:** data dapat dibuat, dicari, difilter, diubah atau dinonaktifkan lewat UI dan API; policy server menegakkan role; validasi dan pagination konsisten; tidak ada hard-delete atas data yang sudah direferensikan.

### Phase 2.2 — Penerimaan dan approval

**Tujuan:** mencatat barang masuk dan mengubah stok hanya setelah alur verifikasi yang disepakati.

**Ruang lingkup:**

- Draft/pending receipt, supplier, tanggal, notes, detail produk, berat awal/final, unit cost, dan subtotal server.
- Karyawan/Admin membuat draft sesuai policy; Admin menerima atau menolak sesuai keputusan Phase 0.
- Proteksi duplicate approval dan idempotensi.
- Approval atomik yang membuat atau memperbarui batch, saldo, stock movement, audit record, dan ledger entry sesuai keputusan produk.
- Riwayat/detail receipt dan pesan status yang menjelaskan dampak stok.

**Selesai jika:** approval hanya berdampak sekali; stok, batch, movement, audit, dan ledger konsisten dalam satu transaksi; rejection tidak menambah ready stock; kegagalan tidak meninggalkan data parsial.

**Ketergantungan:** Phase 2.1 untuk produk dan supplier; keputusan batch dan transisi pending/ready Phase 0.

### Phase 2.3 — Stock overview dan koreksi

**Tujuan:** memberi Admin visibilitas saldo serta histori yang dapat diaudit.

**Ruang lingkup:**

- Ringkasan stok ready, pending, batch, minimum stock, dan low-stock.
- Movement history dengan tanggal, aktor, tipe, sumber, batch, alasan, dan nilai sebelum/sesudah.
- Koreksi stok Admin dengan alasan, before/after, approval/posting bila ditetapkan, dan movement baru.
- Query rekonsiliasi yang melaporkan selisih antara projection, batch, dan movement tanpa memperbaiki diam-diam.

**Selesai jika:** saldo dapat ditelusuri; stok tidak menjadi negatif; movement lama tidak diedit; koreksi dapat diaudit; reconciliation menunjukkan perbedaan yang bisa ditindaklanjuti.

**Ketergantungan:** Phase 2.2 dan keputusan stok Phase 0.

## Phase 3 — Penjualan dan pelaporan

### Phase 3.1 — Sales, FIFO, HPP, dan gross profit

**Tujuan:** mem-posting penjualan dengan alokasi stok yang akurat dan dapat ditelusuri.

**Ruang lingkup:**

- Draft sales, item, tanggal, notes, dan customer opsional.
- Server menghitung subtotal dan total.
- Posting Admin dalam satu transaksi dengan idempotency key dan locking batch.
- Allocator FIFO memakai batch eligible tertua dan membagi item ke beberapa batch bila dibutuhkan.
- Simpan allocation, HPP, gross profit, snapshot produk, stock movement, audit, dan ledger sesuai contract.
- Detail penjualan menampilkan alokasi, HPP, total, dan gross profit.
- Cancellation posted sale hanya melalui reversal yang disetujui.

**Selesai jika:** penjualan memakai FIFO; nilai HPP/profit tersimpan dan tidak berubah saat harga master berubah; insufficient stock menolak seluruh posting tanpa side effect; retry aman; stok dan movement tetap konsisten.

**Ketergantungan:** Phase 2 dan keputusan ledger serta cancellation yang mempengaruhi angka.

### Phase 3.2 — Dashboard, reports, dan export

**Tujuan:** menampilkan ringkasan operasional yang dapat direkonsiliasi terhadap transaksi tersimpan.

**Ruang lingkup:**

- Dashboard Admin dan dashboard terbatas untuk Karyawan.
- Laporan penerimaan, penjualan, stok, movement, HPP, gross profit, dan ledger.
- Filter tanggal, produk, kategori, status, pagination, serta role restriction server-side.
- Ekspor hanya setelah query dan layar laporan tervalidasi; export memakai filter yang sama.

**Selesai jika:** total laporan sesuai transaksi, movement, batch, HPP tersimpan, dan ledger; semua filter diterapkan di server; tidak ada laporan administratif untuk Karyawan.

**Ketergantungan:** data stabil dari Phase 2 dan Phase 3.1.

## Phase 4 — Hardening dan release

**Tujuan:** membuat MVP mudah dipasang, ditinjau, dan dirilis dengan risiko yang terkendali.

**Ruang lingkup:**

- Unit, feature, authorization, integration, rollback, retry, concurrency, dan React behavior tests.
- Fresh migration, seed sintetis, clean-install check, lint/formatter/type check, dan production build.
- Review keamanan session/CSRF/CORS, policy, rate limit, validasi file, secret handling, error logging, dan audit.
- Visual/accessibility review untuk keyboard, focus, kontras, ukuran layar, tabel, form, dan konfirmasi aksi.
- Dokumentasi setup, environment, migration, backup/restore, health check, dan deployment.
- Deployment staging/demo; backup dan smoke check sebelum menyebut siap digunakan.

**Selesai jika:** semua acceptance criteria MVP terpenuhi; pemeriksaan rilis lulus; clean install berhasil; credential tidak terekspos; backup dan restore dipahami; deployment dapat diperiksa dan dipulihkan.

## Phase 5 — Client mobile opsional

**Tujuan:** mengevaluasi client Flutter hanya jika workflow gudang benar-benar membutuhkan kamera, barcode, atau penggunaan mobile khusus.

**Ruang lingkup:** kebutuhan mobile terverifikasi, API reuse, auth token yang dapat dicabut, izin perangkat minimum, dan offline behavior hanya bila disetujui.

**Selesai jika:** ada kebutuhan operasional yang tidak nyaman diselesaikan web mobile; API dan security review menyetujui desain; fitur web MVP tetap stabil.

## Keputusan yang wajib dibawa ke implementation plan

Plan fase harus mencantumkan keputusan terbuka dan dampaknya. Jangan melanjutkan task schema atau workflow yang terdampak sebelum pemilik produk menyetujuinya. Keputusan utama saat roadmap ini dibuat:

1. Batch per penerimaan atau gabungan berdasarkan produk/periode.
2. Aturan berat awal/final dan status pending terhadap ready stock.
3. Format ID yang konsisten pada tabel serta API.
4. Supplier wajib atau opsional pada receipt.
5. Arti dan perhitungan ledger debit/kredit/balance.
6. Aturan reversal/cancellation untuk dokumen yang sudah diposting, jika belum ditentukan oleh policy final.

## Urutan plan yang disarankan

Mulai dengan implementation plan Phase 0 untuk menyelesaikan keputusan dan acceptance examples. Setelah keputusan yang menghalangi foundation jelas, buat plan Phase 1. Selanjutnya buat satu plan untuk setiap subfase Phase 2 dan Phase 3. Jangan membuat satu plan raksasa untuk seluruh MVP.
