<?php
require_once '../config.php';
checkRole(['pemilik']);

date_default_timezone_set('Asia/Jakarta');
$conn = getConnection();
$conn->query("SET time_zone = '+07:00'");

$pageTitle = "Dashboard Pemilik";
$id_cabang = getActiveCabangId(); // null jika 'Semua Cabang', atau integer jika difilter

// ============================================================
// 1. STATISTIK KEUANGAN & TRANSAKSI HARI INI
// ============================================================

// Pendapatan & Jumlah Transaksi Hari Ini
$sql_trx_today = "SELECT COUNT(*) AS total, COALESCE(SUM(total_harga),0) AS pendapatan 
                  FROM transaksi 
                  WHERE DATE(created_at) = CURDATE() " . ($id_cabang ? "AND Id_cabang = '$id_cabang'" : "");
$r2 = $conn->query($sql_trx_today)->fetch_assoc();
$trx_hari_ini = $r2['total'];
$pendapatan_hari_ini = $r2['pendapatan'];

// Laba Bersih Hari Ini (Produk Fisik + Jasa Layanan Saldo)
$sql_profit = "SELECT COALESCE(SUM((dt.harga_jual - dt.harga_modal) * dt.qty), 0) AS keuntungan 
               FROM detail_transaksi dt 
               JOIN transaksi t ON dt.transaksi_id = t.id 
               WHERE DATE(t.created_at) = CURDATE() " . ($id_cabang ? "AND t.Id_cabang = '$id_cabang'" : "");
$r_profit = $conn->query($sql_profit)->fetch_assoc();
$keuntungan_hari_ini = $r_profit['keuntungan'];

// Transaksi Kemarin (Untuk perbandingan persentase)
$sql_trx_kemarin = "SELECT COUNT(*) AS total 
                    FROM transaksi 
                    WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) " . ($id_cabang ? "AND Id_cabang = '$id_cabang'" : "");
$r3 = $conn->query($sql_trx_kemarin)->fetch_assoc();
$trx_kemarin = $r3['total'];

// Total Pendapatan Bulan Ini
$sql_bulan = "SELECT COALESCE(SUM(total_harga),0) AS total 
              FROM transaksi 
              WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) " . ($id_cabang ? "AND Id_cabang = '$id_cabang'" : "");
$pendapatan_bulan = $conn->query($sql_bulan)->fetch_assoc()['total'];

// Perhitungan Persentase Perubahan
if ($trx_kemarin > 0) {
    $pct = round((($trx_hari_ini - $trx_kemarin) / $trx_kemarin) * 100);
    $pct_label = ($pct >= 0 ? '+' : '') . $pct . '% dari kemarin';
} elseif ($trx_hari_ini > 0) {
    $pct_label = 'Baru hari ini';
} else {
    $pct_label = 'Belum ada transaksi';
}

// ============================================================
// 2. STATISTIK INVENTORY / PRODUK
// ============================================================
$sql_prod = "SELECT 
                COUNT(*) AS total, 
                COALESCE(SUM(status='Aktif'), 0) AS aktif,
                COALESCE(SUM(stok > 0 AND stok < 10 AND kategori != 'Layanan Saldo'), 0) AS menipis,
                COALESCE(SUM(stok = 0 AND kategori != 'Layanan Saldo'), 0) AS habis
             FROM produk " . ($id_cabang ? "WHERE Id_cabang = '$id_cabang'" : "");
$r_prod = $conn->query($sql_prod)->fetch_assoc();
$total_produk = $r_prod['total'];
$produk_aktif = $r_prod['aktif'];
$stok_menipis = $r_prod['menipis'];
$stok_habis   = $r_prod['habis'];

// ============================================================
// 3. STATISTIK SALDO REKENING KESELURUHAN (BANK & E-WALLET)
// ============================================================
$r_rek = $conn->query("SELECT COALESCE(SUM(Saldo), 0) AS total_saldo, COUNT(*) AS total_akun FROM Rekening")->fetch_assoc();
$total_saldo_rekening = $r_rek['total_saldo'];
$total_akun_rekening  = $r_rek['total_akun'];

// ============================================================
// 4. GRAFIK 7 HARI TERAKHIR
// ============================================================
$chart_labels = [];
$chart_data   = [];

for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-$i days"));
    $label = date('d/m', strtotime("-$i days"));
    $chart_labels[] = $label;

    $stmt_c = $conn->prepare(
        "SELECT COALESCE(SUM(total_harga),0) AS total 
         FROM transaksi 
         WHERE DATE(created_at) = ? " . ($id_cabang ? "AND Id_cabang = ?" : "")
    );
    if ($id_cabang) {
        $stmt_c->bind_param("si", $tgl, $id_cabang);
    } else {
        $stmt_c->bind_param("s", $tgl);
    }
    $stmt_c->execute();
    $chart_data[] = (int)$stmt_c->get_result()->fetch_assoc()['total'];
    $stmt_c->close();
}

// ============================================================
// 5. TRANSAKSI TERBARU (10 Terakhir)
// ============================================================
$sql_recent = "SELECT t.no_transaksi, t.created_at, t.total_harga, t.metode_bayar, u.nama_lengkap, c.Nama_cabang
               FROM transaksi t
               JOIN users u ON t.user_id = u.id
               LEFT JOIN Cabang c ON t.Id_cabang = c.id
               " . ($id_cabang ? "WHERE t.Id_cabang = '$id_cabang'" : "") . "
               ORDER BY t.created_at DESC
               LIMIT 10";
$transaksi_terbaru = $conn->query($sql_recent)->fetch_all(MYSQLI_ASSOC);

// ============================================================
// 6. RINGKASAN PERFORMA KASIR (Bulan Ini)
// ============================================================
$sql_kasir = "SELECT u.nama_lengkap, c.Nama_cabang, COUNT(t.id) AS jumlah_trx, COALESCE(SUM(t.total_harga),0) AS total
              FROM transaksi t
              JOIN users u ON t.user_id = u.id
              LEFT JOIN Cabang c ON t.Id_cabang = c.id
              WHERE YEAR(t.created_at) = YEAR(CURDATE()) AND MONTH(t.created_at) = MONTH(CURDATE()) 
              " . ($id_cabang ? "AND t.Id_cabang = '$id_cabang'" : "") . "
              GROUP BY t.user_id, u.nama_lengkap, c.Nama_cabang
              ORDER BY total DESC";
$kasir_summary = $conn->query($sql_kasir)->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Pemilik - Fedly Cell POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../asset/style.css">
</head>

<body class="mobile-card-tables">

    <?php include '../layout/sidebar.php'; ?>[cite: 4]
    <?php include '../layout/navbar.php'; ?>[cite: 4]
    <div class="overlay" id="overlay"></div>

    <main class="main-content">
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="fw-bold mb-1">Monitoring Bisnis Fedly Cell</h2>
                <p class="text-muted mb-0">
                    Mode Pantau: 
                    <strong>
                        <i class="fas fa-store me-1 text-primary"></i>
                        <?= $id_cabang ? 'Filter: Cabang ID #' . $id_cabang : 'Semua Cabang (Global)' ?>
                    </strong>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="rekening.php" class="btn btn-outline-primary shadow-sm"><i class="fas fa-wallet me-2"></i>Kelola Saldo</a>[cite: 4]
                <a href="laporan.php" class="btn btn-primary shadow-sm"><i class="fas fa-file-invoice-dollar me-2"></i>Laporan Lengkap</a>[cite: 4]
            </div>
        </div>

        <!-- STATISTICS CARDS -->
        <div class="row g-4 mb-4">
            <!-- Pendapatan Hari Ini -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card green">
                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    <h3 style="font-size:1.15rem;"><?= formatRupiah($pendapatan_hari_ini) ?></h3>
                    <p>Omzet Hari Ini</p>
                    <span class="badge bg-success"><?= $trx_hari_ini ?> Transaksi (<?= $pct_label ?>)</span>
                </div>
            </div>

            <!-- Keuntungan / Laba Bersih Hari Ini -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card purple">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <h3 style="font-size:1.15rem;"><?= formatRupiah($keuntungan_hari_ini) ?></h3>
                    <p>Laba Bersih Hari Ini</p>
                    <span class="badge bg-dark">Margin Real-time</span>
                </div>
            </div>

            <!-- Total Saldo Rekening Pemilik -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card orange">
                    <div class="icon"><i class="fas fa-wallet"></i></div>
                    <h3 style="font-size:1.15rem;"><?= formatRupiah($total_saldo_rekening) ?></h3>
                    <p>Sisa Saldo Rekening</p>
                    <span class="badge bg-warning text-dark"><?= $total_akun_rekening ?> Akun Bank/E-Wallet</span>
                </div>
            </div>

            <!-- Total Produk -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card blue">
                    <div class="icon"><i class="fas fa-box-open"></i></div>
                    <h3><?= number_format($total_produk) ?></h3>
                    <p>Total Produk Terdaftar</p>
                    <span class="badge bg-primary">Aktif: <?= $produk_aktif ?></span>
                </div>
            </div>

            <!-- Stok Menipis -->
            <div class="col-12 col-sm-6 col-lg-3">
                <a href="../pemilik/data-produk.php?filter_stok=menipis" class="stats-card-link text-decoration-none" title="Lihat produk stok hampir habis">[cite: 4]
                    <div class="stats-card yellow">
                        <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <h3><?= number_format($stok_menipis) ?></h3>
                        <p>Stok Hampir Habis</p>
                        <span class="badge bg-warning text-dark">Stok &lt; 10</span>
                    </div>
                </a>
            </div>

            <!-- Stok Habis -->
            <div class="col-12 col-sm-6 col-lg-3">
                <a href="../pemilik/data-produk.php?filter_stok=habis" class="stats-card-link text-decoration-none" title="Lihat produk stok habis">[cite: 4]
                    <div class="stats-card red">
                        <div class="icon"><i class="fas fa-ban"></i></div>
                        <h3><?= number_format($stok_habis) ?></h3>
                        <p>Stok Habis (Kosong)</p>
                        <span class="badge bg-danger">Perlu Restock</span>
                    </div>
                </a>
            </div>

            <!-- Omzet Bulan Ini -->
            <div class="col-12 col-sm-6 col-lg-6">
                <div class="stats-card blue d-flex align-items-center justify-content-between">
                    <div>
                        <p class="mb-1 text-muted">Total Omzet Bulan Ini (<?= date('F Y') ?>)</p>
                        <h2 class="fw-bold mb-0 text-primary"><?= formatRupiah($pendapatan_bulan) ?></h2>
                    </div>
                    <div class="icon fs-1"><i class="fas fa-calendar-alt"></i></div>
                </div>
            </div>
        </div>

        <!-- CHART GRAFIK PENJUALAN -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="chart-container">
                    <div class="chart-header d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i>Tren Penjualan 7 Hari Terakhir</h5>
                        <span class="badge bg-light text-dark border">Realtime Update</span>
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABLE TRANSAKSI TERBARU & RINGKASAN KASIR -->
        <div class="row g-4">
            <!-- 10 Transaksi Terbaru -->
            <div class="col-lg-8">
                <div class="table-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-receipt me-2 text-primary"></i>Transaksi Terbaru</h5>
                        <a href="laporan.php" class="btn btn-sm btn-outline-primary">Lihat Semua Laporan</a>[cite: 4]
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No Transaksi</th>
                                    <th>Cabang</th>
                                    <th>Tanggal</th>
                                    <th>Kasir</th>
                                    <th>Total</th>
                                    <th>Metode</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transaksi_terbaru)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">Belum ada data transaksi.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transaksi_terbaru as $t): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars($t['no_transaksi']) ?></code></td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['Nama_cabang'] ?? 'Pusat') ?></span></td>
                                            <td><?= date('d M Y, H:i', strtotime($t['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($t['nama_lengkap']) ?></td>
                                            <td class="fw-bold"><?= formatRupiah($t['total_harga']) ?></td>
                                            <td>
                                                <?php
                                                $badge = ['Tunai' => 'bg-success', 'Transfer' => 'bg-primary', 'QRIS' => 'bg-info text-dark'];
                                                ?>
                                                <span class="badge <?= $badge[$t['metode_bayar']] ?? 'bg-secondary' ?>">
                                                    <?= htmlspecialchars($t['metode_bayar']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Ringkasan Performa Kasir Bulan Ini -->
            <div class="col-lg-4">
                <div class="table-container h-100">
                    <h5 class="mb-2"><i class="fas fa-users me-2 text-primary"></i>Performa Kasir (Bulan Ini)</h5>
                    <p class="text-muted small mb-3">Rekapitulasi penjualan shift kasir per cabang</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Kasir & Cabang</th>
                                    <th class="text-center">Trx</th>
                                    <th>Total Omzet</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($kasir_summary)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">Belum ada data kasir bulan ini.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($kasir_summary as $k): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($k['nama_lengkap']) ?></strong>
                                                <br><small class="text-muted"><?= htmlspecialchars($k['Nama_cabang'] ?? 'Pusat') ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary rounded-pill"><?= $k['jumlah_trx'] ?></span>
                                            </td>
                                            <td class="small fw-bold"><?= formatRupiah($k['total']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    <script>
        window.chartLabels = <?= json_encode($chart_labels) ?>;
        window.chartData   = <?= json_encode($chart_data) ?>;
    </script>
    <script src="../asset/index.js"></script>[cite: 4]
</body>

</html>