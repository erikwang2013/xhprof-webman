<?php

declare(strict_types=1);

/**
 * Report-page strings — Indonesian (Bahasa Indonesia).
 *
 * Terjemahan mesin, belum diperiksa oleh penutur asli (unreviewed machine
 * translation). Istilah diselaraskan dengan tools/i18n/glossary/id.json.
 *
 * Aturan:
 *   1. Himpunan kunci harus sama persis dengan zh_CN.php, kunci demi kunci:
 *      jangan ditambah, dikurangi, atau diganti namanya. Kalau perlu frasa baru,
 *      ubah zh_CN.php dulu lalu lengkapi ke-12 katalog
 *      (tests/Unit/Core/I18nTest.php akan merah).
 *   2. Satu-satunya markup di dalam nilai adalah `<br>` (kolom sempit dilipat
 *      manual); karakter lain di-escape saat keluaran. Posisi lipatan berbeda
 *      antar bahasa — jangan menyalin posisi lipatan bahasa Mandarin.
 *   3. Variabel/identifier (XHProf, Redis, CPU, IP, bytes, microsec, IWall% dan
 *      identifier kolom sejenis) ikut gaya bahasa masing-masing, tapi jangan
 *      diganti jadi identifier lain.
 *   4. Tiga bidang _meta jangan diubah: lang menentukan <html lang>, dir=rtl
 *      membuat seluruh halaman tersusun dari kanan ke kiri.
 */
return [
    '_meta' => [
        'lang' => 'id',
        'name' => 'Bahasa Indonesia',
        'dir' => 'ltr',
    ],
    'report.title' => 'Laporan Analisis Performa XHProf',
    'nav.brand' => 'Analisis Performa XHProf',
    'nav.home' => 'Beranda',
    'nav.runs' => 'Laporan Eksekusi',
    'nav.symbol' => 'Detail Metode',
    'search.placeholder' => 'Cari nama fungsi/metode...',
    'search.button' => 'Cari',
    'run.col.method' => 'Metode Request',
    'run.col.time' => 'Waktu Request',
    'run.col.ip' => 'IP Sumber',
    'run.col.totalCalls' => 'Total Panggilan Fungsi/Metode',
    'runs.title' => 'Riwayat Request',
    'runs.col.method' => 'Metode',
    'runs.col.url' => 'URL Request',
    'runs.col.time' => 'Waktu Request',
    'runs.col.wt' => 'Waktu (s)',
    'runs.col.mu' => 'Memori (Mb)',
    'runs.col.ip' => 'IP',
    'col.fn' => 'Fungsi/Metode',
    'col.ct' => 'Panggilan',
    'col.Calls%' => 'Panggilan<br>%',
    'col.wt' => 'Total Wall<br>(mikrodetik)',
    'col.IWall%' => 'Total Wall<br>%',
    'col.excl_wt' => 'Sendiri Wall<br>(mikrodetik)',
    'col.EWall%' => 'Sendiri Wall<br>%',
    'col.ut' => 'Total User<br>(mikrodetik)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Sendiri User<br>(mikrodetik)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Total Sys<br>(mikrodetik)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Sendiri Sys<br>(mikrodetik)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'Total<br>Waktu CPU<br>(mikrodetik)',
    'col.ICpu%' => 'Total<br>Waktu CPU<br>%',
    'col.excl_cpu' => 'Sendiri<br>Waktu CPU<br>(mikrodetik)',
    'col.ECpu%' => 'Sendiri<br>Waktu CPU<br>%',
    'col.mu' => 'Total<br>Memori<br>(bytes)',
    'col.IMUse%' => 'Total<br>Memori<br>%',
    'col.excl_mu' => 'Sendiri<br>Memori<br>(bytes)',
    'col.EMUse%' => 'Sendiri<br>Memori<br>%',
    'col.pmu' => 'Total<br>Memori Puncak<br>(bytes)',
    'col.IPMUse%' => 'Total<br>Memori Puncak<br>%',
    'col.excl_pmu' => 'Sendiri<br>Memori Puncak<br>(bytes)',
    'col.EPMUse%' => 'Sendiri<br>Memori Puncak<br>%',
    'col.samples' => 'Total Sampel',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Sendiri Sampel',
    'col.ESamples%' => 'ESamples%',
];
