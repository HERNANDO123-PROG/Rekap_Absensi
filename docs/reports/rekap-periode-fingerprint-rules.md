# Aturan Normatif Rekap Fingerprint Periode — Tahap C

Dokumen ini menetapkan aturan yang telah disepakati untuk mesin rekap fingerprint periode. Dokumen ini bukan spesifikasi implementasi status izin/kehadiran yang masih menunggu keputusan pada bagian akhir. Tidak ada algoritma final yang diimplementasikan pada Tahap C.

## 1. Aturan yang disetujui

### 1.1 Prioritas penentuan status tanggal

Untuk setiap tanggal dan unit, tentukan status tanggal dengan urutan berikut:

1. Penetapan `WorkCalendar` untuk unit dan tanggal, bila tersedia.
2. `WorkSchedule` mingguan yang berlaku, bila kalender tidak menetapkan tanggal.
3. Inferensi kolektif fingerprint yang bersifat sementara, bila kalender dan jadwal mingguan tidak tersedia.
4. Jika bukti tidak cukup, status tanggal belum diketahui. Jangan menyimpulkan hari libur atau Minggu secara otomatis.

Inferensi hanya menghasilkan keputusan untuk laporan. Inferensi tidak boleh menulis `WorkCalendar`, `AttendanceRecord`, ataupun sumber data lainnya.

Profil kategori/pegawai dan jam kerja diperlukan untuk memilih scan. Namun, prioritas A1 secara eksplisit menyebut kalender, jadwal mingguan, lalu inferensi status tanggal. Prioritas dan fallback jam profil yang berkonflik atau tidak tersedia belum ditetapkan; lihat pertanyaan terbuka.

### 1.2 Kalender resmi menetapkan hari kerja

Jika `WorkCalendar` menetapkan hari kerja, tanggal tetap hari kerja meskipun tidak ada fingerprint kolektif. Pegawai tanpa fingerprint dan tanpa izin/Dinas relevan dihitung Tidak Hadir. Ketiadaan scan kolektif tidak boleh membatalkan keputusan kalender.

### 1.3 Inferensi fingerprint kolektif

Inferensi hanya berjalan jika tidak ada penetapan kalender dan tidak ada jadwal mingguan untuk tanggal tersebut.

- Gunakan fingerprint pada tanggal yang sedang diperiksa.
- Denominator adalah seluruh pegawai dalam unit yang dipilih, tidak terpengaruh filter kategori UI.
- Hitung pegawai unik yang memiliki fingerprint, bukan jumlah raw scan.
- Inferensi hari kerja hanya jika proporsinya **lebih dari 50%**.
- Tepat 50%, kurang dari 50%, dan denominator kosong menghasilkan status belum dapat ditentukan; bukan hari libur.
- Hasil inferensi yang memenuhi ambang harus `is_provisional = true` dan `source = fingerprint_inference`.
- Perhitungan tidak boleh mengubah data kalender maupun data sumber.

Secara matematis, indikasi sementara hari kerja diberikan jika `unique_employees_with_fingerprint / denominator_employees > 0.5`. Denominator nol menghasilkan unknown, bukan pembagian atau asumsi default.

### 1.4 Keterlambatan

Keterlambatan dievaluasi hanya setelah kandidat scan masuk dipilih:

- `selected_in > scheduled_start`: terlambat.
- `selected_in <= scheduled_start`: tidak terlambat.
- Tidak ada toleransi tambahan.

Jika kandidat masuk atau jam masuk efektif tidak tersedia, aturan keterlambatan belum menghasilkan nilai; jangan mengubahnya menjadi tidak terlambat secara default.

### 1.5 Pemilihan kandidat fingerprint

- Kandidat masuk dipilih berdasarkan jarak waktu absolut terhadap jam masuk jadwal.
- Kandidat pulang dipilih berdasarkan jarak waktu absolut terhadap jam pulang jadwal.
- Tidak ada batas jendela kandidat.
- Pemilihan untuk masuk dan pulang dilakukan secara terpisah, tetapi satu timestamp/kelompok tidak boleh mengisi kedua peran.
- Jika kandidat berjarak sama dari jam jadwal, pilih kandidat yang lebih awal dari jam jadwal.
- Jika assignment berbeda tidak dapat ditentukan secara valid, beri status ambigu; jangan memakai tie-break yang tidak dinyatakan.

Timestamp/kandidat yang sama tidak boleh dipakai untuk kedua endpoint. Aturan umum untuk menyelesaikan benturan ketika satu kandidat menjadi pilihan terdekat kedua peran belum menjelaskan apakah kandidat alternatif harus dicoba; kasus itu tetap ambigu/pending hingga keputusan B3.

### 1.6 Satu fingerprint

Jika ada satu timestamp unik pada hari kerja:

- Hadir dihitung satu kali, tunduk pada pending B2 untuk scan yang jauh dari jadwal.
- Tetapkan peran berdasarkan jadwal yang paling dekat, tanpa menganggap endpoint pasangannya tersedia.
- Bila perannya masuk, uji keterlambatan tanpa toleransi.
- Bila perannya pulang, uji pulang awal setelah keputusan B1.
- Bila peran tidak dapat ditentukan, tandai ambigu dan jangan menggandakan scan.

### 1.7 Deduplikasi scan berdekatan

Deduplikasi merupakan transformasi hanya untuk perhitungan dan tidak mengubah `FingerprintLog`.

1. Urutkan timestamp dalam urutan waktu.
2. Timestamp pertama yang belum dikelompokkan menjadi acuan kelompok.
3. Timestamp berikutnya masuk kelompok tersebut hanya bila selisih dari timestamp pertama kelompok **maksimal 2 menit**.
4. Jika selisih lebih dari 2 menit dari timestamp pertama, mulai kelompok baru.
5. Nilai representatif kelompok adalah timestamp pertamanya.
6. Deduplikasi terjadi sebelum pemilihan kandidat.
7. Satu kelompok/timestamp terpilih tidak dapat mengisi peran masuk dan pulang sekaligus.

Contoh `06:55, 06:57, 06:59` menghasilkan kelompok representatif `06:55` dan `06:59`; pengelompokan tidak berantai dari scan sebelumnya.

## 2. Fakta model/schema yang relevan

- `LeaveType` saat ini mempunyai lima nilai: `terlambat`, `tidak_hadir`, `pulang_awal`, `dinas`, dan `keluar` (izin keluar). Tidak ada enum `Sick` pada `LeaveType`; `AbsenceReason` mempunyai `sakit`, `izin`, dan `tidak_diketahui`.
- `LeaveRequest` menghubungkan `unit_id`, `employee_id` nullable, `request_date`, `leave_type`, dan `matching_status`; satu pegawai dapat memiliki beberapa permintaan pada tanggal yang sama menurut model/schema. Tidak ada constraint unik pegawai-tanggal yang terlihat.
- `FingerprintLog` menghubungkan unit, pegawai nullable, tanggal, waktu, status pencocokan, serta `source_category_id` nullable. `MatchingStatus` bernilai `matched`, `unmatched`, atau `ambiguous`.
- `WorkCalendar` memiliki unique key unit-tanggal serta `is_working_day`, waktu mulai/selesai, dan tipe.
- `WorkSchedule` menyimpan jadwal mingguan berdasarkan unit dan nomor hari, dengan `is_working_day` dan jam.
- `WorkScheduleProfileRule` menyimpan aturan hari/jam pada profil, tetapi tidak menyediakan rentang tanggal efektif.
- `Employee` menyimpan `unit_id`, `category_id`, `work_schedule_profile_id`, status, `nama_lengkap`, dan `namafinger`. Relasi yang diperiksa tidak menunjukkan histori unit/kategori/profil.
- Migrasi `2026_10_14_000001_replace_employee_effective_dates_with_employment_periods.php` membuat tabel `employee_employment_periods` berisi `employee_id`, `start_date`, dan `end_date`; tidak ada model `EmploymentPeriod` yang ditemukan pada direktori model. Tabel ini memberi periode kerja, tetapi tidak menyimpan unit/kategori historis.

Konsekuensi: denominator seluruh pegawai unit dapat dihitung dari data pegawai saat ini, namun relevansi mereka pada tanggal lampau tidak sepenuhnya terjawab oleh relasi unit saat ini. `employee_employment_periods` dapat membantu membatasi tanggal kerja pegawai, tetapi tidak merekam perpindahan unit. Jangan mengubah denominator yang disetujui secara diam-diam; keputusan pemetaan unit historis tercatat pada B10.

## 3. Matriks test normatif A1–A7

Fixture dapat dibuat dengan model Eloquent yang tersedia (`Employee`, `Unit`, `WorkCalendar`, `WorkSchedule`, `FingerprintImportBatch`, `FingerprintLog`, profil/jadwal, dan `LeaveRequest`). Tidak ditemukan factory domain pada pemeriksaan ini selain `UserFactory`; test sebaiknya menggunakan helper factory lokal seperti tes absensi yang ada. Matriks ini adalah expected behavior untuk implementasi masa depan, bukan hasil tes eksekusi.

| ID | Aturan | Fixture / kondisi | Expected result | Status |
|---|---|---|---|---|
| C01 | A1 kalender mengungguli jadwal mingguan | Kalender tanggal menyatakan libur; jadwal mingguan menyatakan kerja; fingerprint ada | Status tanggal libur resmi; tidak diganti oleh jadwal/fingerprint; kalender tidak berubah | Normatif |
| C02 | A1 kalender mengungguli inferensi | Kalender menetapkan hari kerja; fingerprint kurang dari mayoritas | Hari kerja resmi tetap berlaku; inferensi tidak membatalkan | Normatif |
| C03 | A1 jadwal mingguan digunakan tanpa kalender | Tidak ada kalender; ada jadwal mingguan untuk hari itu; fingerprint tidak mencapai mayoritas | Ikuti `WorkSchedule`; inferensi tidak dipakai | Normatif |
| C04 | A1 inferensi hanya ketika dua sumber tidak tersedia | Tidak ada kalender dan tidak ada jadwal mingguan; fingerprint unik >50% pegawai unit | Indikasi kerja sementara, `is_provisional=true`, `source=fingerprint_inference` | Normatif |
| C05 | A1 bukti tidak cukup | Tidak ada kalender/jadwal; persentase fingerprint <=50% | Status hari unknown; tidak disimpulkan libur/Minggu | Normatif |
| C06 | A2 kalender kerja tanpa fingerprint kolektif | Kalender menyatakan kerja; pegawai target tidak punya scan/izin/Dinas relevan | Hari tetap kerja; pegawai target Tidak Hadir | Normatif, metrik izin tunduk B6 |
| C07 | A3 ambang lebih dari 50% | Denominator 10 pegawai; 6 pegawai unik memiliki scan | Indikasi kerja provisional, karena 6/10 > 50% | Normatif |
| C08 | A3 tepat 50% | Denominator 10; 5 pegawai unik memiliki scan | Unknown; tidak ada inferensi libur | Normatif |
| C09 | A3 kurang dari 50% | Denominator 10; 4 pegawai unik memiliki scan | Unknown; tidak ada inferensi libur | Normatif |
| C10 | A3 denominator kosong | Unit tidak memiliki pegawai denominator | Unknown; tidak ada pembagian nol/default | Normatif |
| C11 | A3 pegawai dengan banyak scan | Denominator 10; satu pegawai memiliki banyak scan, empat pegawai lain masing-masing memiliki scan (5 pegawai unik) | 5/10 tepat 50%; unknown. Jumlah scan tambahan tidak mengubah numerator | Normatif |
| C12 | A3 filter kategori tidak mengubah denominator | Laporan difilter ke kategori dengan 2 dari 10 pegawai; 6/10 seluruh unit memiliki scan | Denominator tetap 10; hasil provisional kerja. Filter hanya membatasi hasil tabel | Normatif |
| C13 | A4 datang tepat jadwal | Kandidat masuk terpilih sama dengan jam mulai | Tidak terlambat | Normatif |
| C14 | A4 datang sebelum jadwal | Kandidat masuk terpilih lebih awal dari jam mulai | Tidak terlambat | Normatif |
| C15 | A4 datang setelah jadwal | Kandidat masuk terpilih lebih akhir dari jam mulai | Terlambat; tanpa toleransi | Normatif |
| C16 | A4 kandidat masuk tidak tersedia | Tidak ada kandidat masuk terpilih | Metrik terlambat tidak diputuskan oleh A4; gunakan status belum tersedia/ambigu, tidak diubah menjadi nol | Normatif untuk non-inferensi |
| C17 | A5 nearest candidate | Beberapa kelompok waktu dan jam mulai/jam pulang diketahui | Kandidat tiap peran memiliki jarak absolut terkecil terhadap jam jadwal masing-masing | Normatif |
| C18 | A5 tie lintas sebelum/sesudah | Dua kandidat sama jaraknya dari jam jadwal, satu sebelum dan satu sesudah | Pilih kandidat sebelum jam jadwal | Normatif |
| C19 | A5 waktu tidak dipakai dua kali | Kandidat terdekat yang sama untuk kedua peran | Timestamp/kelompok tidak boleh ditetapkan ke kedua peran; jika assignment valid alternatif tidak dapat ditentukan, hasil ambigu | Larangan reuse normatif; penanganan alternatif pending B3 |
| C20 | A5 pemilihan ambigu | Bukti kandidat tidak cukup untuk satu assignment valid | Tandai ambiguous, tidak membuat endpoint secara sewenang-wenang | Normatif; kriteria lengkap pending B3 |
| C21 | A6 satu timestamp jelas lebih dekat ke jam masuk | Satu kelompok; jarak ke start lebih kecil daripada ke end | Hadir satu kali; peran masuk; evaluasi keterlambatan; endpoint pulang tetap kosong | Normatif, Hadir tunduk B2 untuk scan di luar jadwal |
| C22 | A6 satu timestamp jelas lebih dekat ke jam pulang | Satu kelompok; jarak ke end lebih kecil daripada ke start | Hadir satu kali; peran pulang; evaluasi pulang awal tunduk B1; endpoint masuk tetap kosong | Normatif, Hadir tunduk B2 |
| C23 | A6 satu timestamp tie/role tidak pasti | Jarak ke start dan end sama atau jadwal tidak cukup membedakan | Ambigu; tidak menggandakan scan | Normatif untuk tie; fallback jadwal pending B8 |
| C24 | A7 batas deduplikasi tepat 2 menit | Timestamp `06:55`, `06:57` | Satu kelompok dengan representatif `06:55` | Normatif |
| C25 | A7 melewati batas 2 menit | Timestamp `06:55`, `06:58` | Dua kelompok: `06:55`, `06:58` | Normatif |
| C26 | A7 bukan pengelompokan berantai | Timestamp `06:55`, `06:57`, `06:59` | Dua kelompok: `06:55` dan `06:59` | Normatif |
| C27 | A7 data raw tetap utuh | Jalankan deduplikasi dan pemilihan di fixture fingerprint logs | Timestamp/jumlah rows/atribut raw tetap sama sebelum dan sesudah | Normatif |
| C28 | A5/A7 assignment berbeda | Scan hasil deduplikasi dipilih untuk peran masuk | Tetapkan kandidat masuk terlebih dahulu; keluarkan kelompoknya dari kandidat pulang, lalu pilih kandidat pulang berperingkat tertinggi yang tersisa. Jika tidak ada kandidat tersisa, endpoint pulang tetap null dan kasus ambigu | Normatif |

### Kontrak output mesin hitung (Tahap F)

- Pemilihan dua endpoint tidak boleh menggunakan kelompok yang sama. Kandidat masuk berperingkat tertinggi dipilih lebih dahulu; kelompok tersebut dikeluarkan dari ranking pulang sebelum kandidat pulang dipilih. Kandidat pulang berikutnya mengikuti ranking jarak jadwal dan tie-break C18.
- `summary.unknown_date_count` adalah jumlah tanggal periode yang statusnya `unknown`, dihitung sekali per tanggal dan tidak bergantung pada pegawai di halaman.
- `summary.page_ambiguous_employee_day_count` adalah jumlah baris harian ambigu di antara pegawai yang ada pada halaman respons saat ini. Field ini hanya berlaku untuk halaman tersebut; gunakan pagination untuk menghitung atau menampilkan hasil halaman lain. Jangan menyebutnya sebagai total ambigu seluruh hasil terfilter.
- `summary.provisional_day_count` adalah jumlah tanggal periode yang statusnya ditetapkan melalui inferensi fingerprint, dihitung sekali per tanggal.
- Nilai metrik yang belum dapat dipastikan tetap `null` pada harian dan agregat; agregasi tidak boleh mengubahnya menjadi nol.
- `daily.leave_status` bernilai `none` bila tidak ada permohonan izin yang lolos filter kelayakan laporan dan `unverifiable` bila ada. Nilai `unverifiable` menyatakan persetujuan/cakupan izin tidak dapat dibuktikan; `matching_status` dan status batch bukan bukti persetujuan. `leave_types`, `reason_or_warning`, `history_limited`, flag ambiguity, dan nilai metrik nullable tetap disediakan untuk penjelasan harian.
- Ekspor wajib memakai filter unit, rentang tanggal, kategori, dan pencarian yang sama dengan tabel, serta mengambil seluruh halaman/pegawai yang cocok. Ekspor tidak boleh hanya mengekspor halaman aktif.

### Kasus B — belum menjadi expected result final

Test di bawah harus ditulis setelah keputusan terkait disetujui. Jangan mengisi expected result sekarang.

| ID | Isu | Kondisi uji | Status |
|---|---|---|---|
| P01 | B1 Pulang awal | Kandidat pulang terpilih lebih awal dari jam pulang | **PENDING BUSINESS DECISION** |
| P02 | B2 Hadir untuk scan jauh dari jadwal | Satu atau lebih scan, tetapi kandidat tidak cocok secara bermakna dengan jadwal | **PENDING BUSINESS DECISION** |
| P03 | B3 ambiguity dan agregat | Kandidat bersaing, tie, benturan peran, atau satu scan tanpa jadwal | **PENDING BUSINESS DECISION** |
| P04 | B4 kalender libur dengan scan | Kalender menyatakan libur; pegawai memiliki scan | **PENDING BUSINESS DECISION** |
| P05 | B5 Dinas dengan scan | Dinas dan fingerprint pada tanggal sama; scan menunjukkan telat/pulang awal | **PENDING BUSINESS DECISION** |
| P06 | B6 izin selain Dinas | Masing-masing jenis `tidak_hadir`, `terlambat`, `pulang_awal`, `keluar`; `LeaveType` tidak memuat sakit sebagai jenis izin | **PENDING BUSINESS DECISION** |
| P07 | B7 izin keluar | Permintaan ganda, tanpa scan pasangan, scan parsial, atau konflik Dinas | **PENDING BUSINESS DECISION** |
| P08 | B8 jadwal/jam tidak tersedia | Hari kerja diketahui, jam kandidat tidak tersedia atau profil tidak valid | **PENDING BUSINESS DECISION** |
| P09 | B9 histori assignment | Pegawai berganti kategori/profil; laporan tanggal sebelum/sesudah perubahan | **PENDING BUSINESS DECISION** |
| P10 | B10 denominator lampau | Periode pegawai tersedia, tetapi riwayat unit tidak; pegawai berpindah unit | **PENDING BUSINESS DECISION** |
| P11 | Kelayakan log | Log tidak cocok/ambigu, batch belum selesai, atau unit log berbeda dari unit pegawai | **PENDING BUSINESS DECISION** |

## 4. Keputusan minimum yang masih diminta

Model menegaskan lima jenis izin aktual: `terlambat`, `tidak_hadir`, `pulang_awal`, `dinas`, `keluar`. Data ketidakhadiran sakit direpresentasikan sebagai `AbsenceReason`, bukan `LeaveType`. Beberapa `LeaveRequest` dapat berada pada tanggal yang sama; karena itu aturan konflik diperlukan.

1. **Hadir/scan (B2):** apakah keberadaan minimal satu fingerprint mentah yang matched pada hari kerja selalu cukup untuk Hadir, sekalipun tidak ada kandidat endpoint yang valid?
2. **Kandidat ambigu (B3) dan scan bertabrakan:** bila satu kelompok adalah kandidat terdekat kedua peran, bolehkah algoritma mencoba kandidat terbaik berikutnya untuk peran kedua? Bila tetap tie/tidak valid, apakah Hadir masih dihitung dari keberadaan scan?
3. **Pulang awal (B1):** apakah kandidat pulang lebih awal daripada jadwal dihitung Pulang Awal tanpa toleransi, sejajar dengan aturan keterlambatan?
4. **Libur dan Dinas (B4–B5):** apakah fingerprint pada kalender libur dihitung Hadir? Pada hari Dinas, apakah jam scan tetap dinilai Terlambat/Pulang Awal?
5. **Izin (B6–B7):** rekomendasi aman: jenis `tidak_hadir` mengecualikan alpha tetapi bukan Hadir; `terlambat`/`pulang_awal` tetap membutuhkan kandidat scan yang terukur; `keluar` menjadi metrik terpisah dan tidak menghapus Hadir. Untuk konflik/permintaan ganda, rekomendasi adalah tandai ambigu dan jangan hitung lebih dari sekali. Mohon persetujuan atau koreksi.
6. **Jadwal dan histori (B8–B9):** bila tanggal kerja diketahui tetapi jam atau riwayat kategori/profil tidak dapat dibuktikan, rekomendasi: Hari Kerja tetap dapat dihitung; metrik yang memerlukan jam bernilai belum tersedia; jangan memakai kategori/jadwal saat ini untuk tanggal lampau. Setujukah fallback ini?
7. **Denominator unit historis (B10):** `employee_employment_periods` menyimpan periode kerja pegawai, tetapi tidak menyimpan histori unit. Rekomendasi: gunakan pegawai yang periode kerjanya mencakup tanggal dan unit saat ini sama dengan unit filter, sambil menandai keterbatasan perpindahan unit; atau minta sumber histori unit sebelum inferensi historis diaktifkan.
8. **Kelayakan fingerprint (P11):** karena `FingerprintLog.employee_id` nullable dan status pencocokan dapat `matched`, `unmatched`, atau `ambiguous`, rekomendasi adalah numerator inferensi dan kandidat scan individu hanya memakai log yang terhubung ke pegawai dan berstatus matched, dengan unit log sama dengan unit pegawai. Mohon persetujuan tentang status batch yang dapat digunakan (misalnya hanya batch selesai), dan apakah log ambigu harus ditampilkan sebagai diagnostik tanpa memengaruhi metrik.

## 5. Tahap implementasi berikutnya

Setelah keputusan pending disetujui:

1. Buat test unit resolver kalender/inferensi dengan C01–C12.
2. Buat test pemilihan kandidat/deduplikasi dengan C13–C28, termasuk invariant data mentah tetap sama.
3. Implementasikan resolver status tanggal dan resolver scan terpisah, tanpa mengubah pipeline pemrosesan resmi.
4. Implementasikan agregasi per pegawai hanya untuk aturan yang disahkan; nilai tidak tersedia tetap nullable dan ambiguity memiliki alasan/provenance.
5. Jalankan matriks regresi serta pastikan service tidak menulis ke database.

Tidak ada perubahan pada service foundation, UI, endpoint, ekspor, schema, atau data bisnis pada Tahap C.
