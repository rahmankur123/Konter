<?php
require_once '../config.php';
checkRole(['kasir']);

date_default_timezone_set('Asia/Jakarta');
$conn = getConnection();
$conn->query("SET time_zone = '+07:00'");

// Ambil id cabang kasir yang aktif
$id_cabang = getActiveCabangId();

if (!$id_cabang) {
    die("Akses ditolak: Akun kasir belum terhubung ke cabang manapun. Hubungi pemilik toko.");
}

// ============================================================
// STATISTIK CARDS (TERISOLASI CABANG KASIR)
// ============================================================

// 1. Total Produk & Produk Aktif di Cabang Ini
$stmt = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status='Aktif'), 0) AS aktif FROM produk WHERE Id_cabang = ?");
$stmt->bind_param("i", $id_cabang);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$total_produk = $r['total'];
$produk_aktif = $r['aktif'];
$stmt->close();

// 2. Transaksi & Pendapatan Hari Ini di Cabang Ini
$stmt2 = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(total_harga), 0) AS pendapatan FROM transaksi WHERE Id_cabang = ? AND DATE(created_at) = CURDATE()");
$stmt2->bind_param("i", $id_cabang);
$stmt2->execute();
$r2 = $stmt2->get_result()->fetch_assoc();
$trx_hari_ini = $r2['total'];
$pendapatan_hari_ini = $r2['pendapatan'];
$stmt2->close();

// 3. Transaksi Kemarin di Cabang Ini (untuk perbandingan persentase)
$stmt3 = $conn->prepare("SELECT COUNT(*) AS total FROM transaksi WHERE Id_cabang = ? AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
$stmt3->bind_param("i", $id_cabang);
$stmt3->execute();
$r3 = $stmt3->get_result()->fetch_assoc();
$trx_kemarin = $r3['total'];
$stmt3->close();

// 4. Stok Menipis dan Habis di Cabang Ini
$stmt4 = $conn->prepare("SELECT COUNT(*) AS total FROM produk WHERE Id_cabang = ? AND stok > 0 AND stok < 10");
$stmt4->bind_param("i", $id_cabang);
$stmt4->execute();
$stok_menipis = $stmt4->get_result()->fetch_assoc()['total'];
$stmt4->close();

$stmt5 = $conn->prepare("SELECT COUNT(*) AS total FROM produk WHERE Id_cabang = ? AND stok = 0");
$stmt5->bind_param("i", $id_cabang);
$stmt5->execute();
$stok_habis = $stmt5->get_result()->fetch_assoc()['total'];
$stmt5->close();

// Hitung persentase perubahan transaksi
if ($trx_kemarin > 0) {
    $pct = round((($trx_hari_ini - $trx_kemarin) / $trx_kemarin) * 100);
    $pct_label = ($pct >= 0 ? '+' : '') . $pct . '% dari kemarin';
} elseif ($trx_hari_ini > 0) {
    $pct_label = 'Baru hari ini';
} else {
    $pct_label = 'Belum ada transaksi';
}

// ============================================================
// GRAFIK 7 HARI TERAKHIR (CABANG KASIR)
// ============================================================
$chart_labels = [];
$chart_data   = [];

for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-$i days"));
    $label = date('d/m', strtotime("-$i days"));
    $chart_labels[] = $label;

    $stmt_c = $conn->prepare("SELECT COALESCE(SUM(total_harga), 0) AS total FROM transaksi WHERE Id_cabang = ? AND DATE(created_at) = ?");
    $stmt_c->bind_param("is", $id_cabang, $tgl);
    $stmt_c->execute();
    $chart_data[] = (int)$stmt_c->get_result()->fetch_assoc()['total'];
    $stmt_c->close();
}

// ============================================================
// TRANSAKSI TERBARU (10 transaksi terakhir cabang ini)
// ============================================================
$stmt_t = $conn->prepare("
    SELECT t.no_transaksi, t.created_at, t.total_harga, t.metode_bayar, u.nama_lengkap
    FROM transaksi t
    JOIN users u ON t.user_id = u.id
    WHERE t.Id_cabang = ?
    ORDER BY t.created_at DESC
    LIMIT 10
");
$stmt_t->bind_param("i", $id_cabang);
$stmt_t->execute();
$transaksi_terbaru = $stmt_t->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_t->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Kasir - Fedly Cell POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../asset/style.css">
</head>

<body class="kasir-modern dashboard-page mobile-card-tables">

    <?php include '../layout/sidebar.php'; ?>[cite: 4]
    <?php include '../layout/navbar.php'; ?>[cite: 4]
    <div class="overlay" id="overlay"></div>

    <main class="main-content">
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="fw-bold mb-1">Dashboard Kasir</h2>
                <p class="text-muted mb-0">Cabang Penugasan: <strong><i class="fas fa-store me-1 text-primary"></i><?= htmlspecialchars($_SESSION['nama_cabang'] ?? 'Cabang Utama') ?></strong></p>
            </div>
            <div>
                <a href="transaksi.php" class="btn btn-primary shadow-sm"><i class="fas fa-cash-register me-2"></i>Buka Kasir POS</a>
            </div>
        </div>

        <!-- STATISTICS CARDS -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card blue">
                    <div class="icon"><i class="fas fa-box"></i></div>
                    <h3><?= number_format($total_produk) ?></h3>
                    <p>Total Produk Cabang</p>
                    <span class="badge bg-primary">Aktif: <?= $produk_aktif ?></span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card green">
                    <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    <h3><?= number_format($trx_hari_ini) ?></h3>
                    <p>Transaksi Hari Ini</p>
                    <span class="badge bg-success"><?= $pct_label ?></span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card orange">
                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    <h3 style="font-size:1.1rem;"><?= formatRupiah($pendapatan_hari_ini) ?></h3>
                    <p>Pendapatan Hari Ini</p>
                    <span class="badge bg-warning text-dark"><?= date('d/m/Y') ?></span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <a href="../kasir/data-produk.php?filter_stok=menipis" class="stats-card-link text-decoration-none" title="Lihat produk stok hampir habis">[cite: 4]
                    <div class="stats-card yellow">
                        <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <h3><?= number_format($stok_menipis) ?></h3>
                        <p>Stok Hampir Habis</p>
                        <span class="badge bg-warning text-dark">Stok &lt; 10</span>
                    </div>
                </a>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <a href="../kasir/data-produk.php?filter_stok=habis" class="stats-card-link text-decoration-none" title="Lihat produk stok habis">[cite: 4]
                    <div class="stats-card red">
                        <div class="icon"><i class="fas fa-ban"></i></div>
                        <h3><?= number_format($stok_habis) ?></h3>
                        <p>Stok Habis</p>
                        <span class="badge bg-danger">Harus Restock</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- CHART -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="chart-container">
                    <div class="chart-header d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i>Grafik Penjualan 7 Hari Terakhir (Cabang Ini)</h5>
                        <span class="badge bg-light text-dark border">Update Realtime</span>
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABLE TRANSAKSI TERBARU -->
        <div class="row">
            <div class="col-12">
                <div class="table-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-receipt me-2 text-primary"></i>10 Transaksi Terbaru Cabang Ini</h5>
                        <a href="laporan.php" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No Transaksi</th>
                                    <th>Tanggal</th>
                                    <th>Kasir</th>
                                    <th>Total Bayar</th>
                                    <th>Metode</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transaksi_terbaru)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Belum ada transaksi di cabang ini.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transaksi_terbaru as $t): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($t['no_transaksi']) ?></strong></td>
                                            <td><?= date('d M Y, H:i', strtotime($t['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($t['nama_lengkap']) ?></td>
                                            <td><strong><?= formatRupiah($t['total_harga']) ?></strong></td>
                                            <td>
                                                <?php
                                                $badge = ['Tunai' => 'bg-success', 'Transfer' => 'bg-primary', 'QRIS' => 'bg-info text-dark'];
                                                ?>
                                                <span class="badge <?= $badge[$t['metode_bayar']] ?? 'bg-secondary' ?> badge-status">
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