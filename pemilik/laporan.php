<?php
require_once '../config.php';
checkRole(['kasir', 'pemilik']);

$conn = getConnection();
ensureBuktiTransferColumn($conn);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$conn->query("SET time_zone = '+07:00'");
date_default_timezone_set('Asia/Jakarta');

$pageTitle = "Laporan Penjualan";
$isOwner = ($_SESSION['role'] === 'pemilik');

// ============================================================
// FILTER & AKSES CABANG
// ============================================================
$tanggal_dari   = $_GET['tanggal_dari'] ?? date('Y-m-01');
$tanggal_sampai = $_GET['tanggal_sampai'] ?? date('Y-m-d');
$metode_filter  = $_GET['metode_bayar'] ?? '';
$kasir_filter   = $_GET['kasir'] ?? '';

// Kasir terkunci ke cabangnya, Pemilik bisa memilih cabang atau global
if (!$isOwner) {
    $cabang_filter = $_SESSION['id_cabang'];
} else {
    $cabang_filter = $_GET['cabang'] ?? '';
}

// ============================================================
// SUSUN KLAUSA WHERE
// ============================================================
$where  = ["DATE(t.created_at) BETWEEN ? AND ?"];
$params = [$tanggal_dari, $tanggal_sampai];
$types  = "ss";

// Filter Cabang
if ($cabang_filter && intval($cabang_filter) > 0) {
    $where[]  = "t.Id_cabang = ?";
    $params[] = intval($cabang_filter);
    $types   .= "i";
}

// Filter Metode Bayar
if ($metode_filter && in_array($metode_filter, ['Tunai', 'Transfer', 'QRIS'])) {
    $where[]  = "t.metode_bayar = ?";
    $params[] = $metode_filter;
    $types   .= "s";
}

// Filter Kasir
if ($kasir_filter && intval($kasir_filter) > 0) {
    $where[]  = "t.user_id = ?";
    $params[] = intval($kasir_filter);
    $types   .= "i";
}

$where_clause = implode(' AND ', $where);

// ============================================================
// 1. QUERY DAFTAR TRANSAKSI
// ============================================================
$stmt = $conn->prepare(
    "SELECT t.*, u.nama_lengkap, c.Nama_cabang 
     FROM transaksi t 
     JOIN users u ON t.user_id = u.id 
     LEFT JOIN Cabang c ON t.Id_cabang = c.id
     WHERE $where_clause 
     ORDER BY t.created_at DESC"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transaksi = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ============================================================
// 2. STATISTIK OMZET & PEMBAYARAN
// ============================================================
$stmt_stat = $conn->prepare(
    "SELECT 
        COUNT(*) AS total_transaksi,
        COALESCE(SUM(total_harga), 0) AS total_pendapatan,
        COALESCE(SUM(CASE WHEN metode_bayar = 'Tunai' THEN total_harga ELSE 0 END), 0) AS tunai,
        COALESCE(SUM(CASE WHEN metode_bayar = 'Transfer' THEN total_harga ELSE 0 END), 0) AS transfer,
        COALESCE(SUM(CASE WHEN metode_bayar = 'QRIS' THEN total_harga ELSE 0 END), 0) AS qris
     FROM transaksi t
     WHERE $where_clause"
);
$stmt_stat->bind_param($types, ...$params);
$stmt_stat->execute();
$statistik = $stmt_stat->get_result()->fetch_assoc();
$stmt_stat->close();

// ============================================================
// 3. STATISTIK KEUNTUNGAN (LABA BERSIH)
// ============================================================
// Menggunakan harga_modal snapshot pada detail_transaksi agar akurat
$stmt_profit = $conn->prepare(
    "SELECT 
        COALESCE(SUM((dt.harga_jual - dt.harga_modal) * dt.qty), 0) AS total_keuntungan
     FROM detail_transaksi dt
     JOIN transaksi t ON dt.transaksi_id = t.id
     WHERE $where_clause"
);
$stmt_profit->bind_param($types, ...$params);
$stmt_profit->execute();
$profit = $stmt_profit->get_result()->fetch_assoc();
$total_keuntungan = $profit['total_keuntungan'];
$stmt_profit->close();

// ============================================================
// 4. PRODUK TERLARIS
// ============================================================
$stmt_produk = $conn->prepare(
    "SELECT 
        dt.nama_produk,
        SUM(dt.qty) AS total_qty,
        SUM(dt.subtotal) AS total_nilai
     FROM detail_transaksi dt
     JOIN transaksi t ON dt.transaksi_id = t.id
     WHERE $where_clause
     GROUP BY dt.nama_produk
     ORDER BY total_qty DESC
     LIMIT 10"
);
$stmt_produk->bind_param($types, ...$params);
$stmt_produk->execute();
$produk_terlaris = $stmt_produk->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_produk->close();

// ============================================================
// 5. MASTER DATA UNTUK FILTER
// ============================================================
$cabang_list = [];
if ($isOwner) {
    $cabang_list = $conn->query("SELECT id, Nama_cabang FROM Cabang ORDER BY Nama_cabang ASC")->fetch_all(MYSQLI_ASSOC);
    $kasir_query = "SELECT id, nama_lengkap FROM users WHERE role = 'kasir' ORDER BY nama_lengkap ASC";
} else {
    $kasir_query = "SELECT id, nama_lengkap FROM users WHERE role = 'kasir' AND id_cabang = '{$_SESSION['id_cabang']}' ORDER BY nama_lengkap ASC";
}
$kasir_list = $conn->query($kasir_query)->fetch_all(MYSQLI_ASSOC);

// ============================================================
// 6. EKSPOR KE CSV
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_penjualan_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['No Transaksi', 'Cabang', 'Tanggal', 'Pelanggan', 'Total', 'Bayar', 'Kembalian', 'Metode', 'Kasir', 'Bukti Transfer']);
    
    foreach ($transaksi as $t) {
        fputcsv($output, [
            $t['no_transaksi'],
            $t['Nama_cabang'] ?? 'Pusat',
            date('d/m/Y H:i', strtotime($t['created_at'])),
            $t['nama_pelanggan'],
            $t['total_harga'],
            $t['total_bayar'],
            $t['kembalian'],
            $t['metode_bayar'],
            $t['nama_lengkap'],
            $t['bukti_transfer'] ?? ''
        ]);
    }
    
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Fedly Cell POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../asset/style.css">
    <style>
        @media print {
            .sidebar, .navbar-custom, .overlay, .no-print, .report-summary, .print-hide {
                display: none !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 12px !important;
            }
            .print-detail {
                width: 100% !important;
                max-width: 100% !important;
                flex: 0 0 100% !important;
            }
            .table-responsive {
                overflow: visible !important;
            }
            .table-container {
                box-shadow: none !important;
                border: 1px solid #dee2e6 !important;
                margin-top: 12px !important;
            }
            table { width: 100% !important; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            body, body * { color: #000 !important; -webkit-text-fill-color: #000 !important; background: white !important; }
        }
    </style>
</head>

<body class="kasir-modern mobile-card-tables">

    <?php include '../layout/sidebar.php'; ?>
    <?php include '../layout/navbar.php'; ?>
    <div class="overlay" id="overlay"></div>

    <main class="main-content">
        <div class="mb-4">
            <h2 class="fw-bold">Laporan Penjualan</h2>
            <p class="text-muted">
                <?= $isOwner ? 'Laporan pendapatan dan laba operasional Fedly Cell' : 'Laporan penjualan cabang <strong>' . htmlspecialchars($_SESSION['nama_cabang'] ?? '') . '</strong>' ?>
            </p>
        </div>

        <!-- FILTER FORM -->
        <div class="table-container mb-4 no-print">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Laporan</h5>
            <form method="GET" action="laporan.php" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tanggal Dari</label>
                    <input type="date" class="form-control" name="tanggal_dari" value="<?= htmlspecialchars($tanggal_dari) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tanggal Sampai</label>
                    <input type="date" class="form-control" name="tanggal_sampai" value="<?= htmlspecialchars($tanggal_sampai) ?>" required>
                </div>

                <?php if ($isOwner): ?>
                <div class="col-md-2">
                    <label class="form-label">Cabang</label>
                    <select class="form-select" name="cabang">
                        <option value="">Semua Cabang</option>
                        <?php foreach ($cabang_list as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $cabang_filter == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['Nama_cabang']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-2">
                    <label class="form-label">Metode Bayar</label>
                    <select class="form-select" name="metode_bayar">
                        <option value="">Semua Metode</option>
                        <option value="Tunai" <?= $metode_filter === 'Tunai' ? 'selected' : '' ?>>Tunai</option>
                        <option value="Transfer" <?= $metode_filter === 'Transfer' ? 'selected' : '' ?>>Transfer</option>
                        <option value="QRIS" <?= $metode_filter === 'QRIS' ? 'selected' : '' ?>>QRIS</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Kasir</label>
                    <select class="form-select" name="kasir">
                        <option value="">Semua Kasir</option>
                        <?php foreach ($kasir_list as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= $kasir_filter == $k['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($k['nama_lengkap']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Tampilkan
                    </button>
                    <a href="laporan.php" class="btn btn-secondary">
                        <i class="fas fa-redo me-2"></i>Reset Filter
                    </a>
                    <button type="button" class="btn btn-success btn-cetak-laporan">
                        <i class="fas fa-print me-2"></i>Cetak Laporan
                    </button>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-info text-white">
                        <i class="fas fa-file-csv me-2"></i>Export CSV
                    </a>
                </div>
            </form>
        </div>

        <!-- STATISTIK CARDS -->
        <div class="row g-4 mb-4 report-summary">
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card blue">
                    <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    <h3><?= number_format($statistik['total_transaksi']) ?></h3>
                    <p>Total Transaksi</p>
                    <span class="badge bg-primary"><?= date('d/m/Y', strtotime($tanggal_dari)) ?> - <?= date('d/m/Y', strtotime($tanggal_sampai)) ?></span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card green">
                    <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                    <h3 style="font-size:1.15rem;"><?= formatRupiah($statistik['total_pendapatan']) ?></h3>
                    <p>Total Omzet Penjualan</p>
                    <span class="badge bg-success">Semua Metode</span>
                </div>
            </div>

            <!-- Card Keuntungan / Laba Bersih -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card purple">
                    <div class="icon"><i class="fas fa-chart-line"></i></div>
                    <h3 style="font-size:1.15rem;"><?= formatRupiah($total_keuntungan) ?></h3>
                    <p>Total Laba Bersih</p>
                    <span class="badge bg-dark">Margin Keuntungan</span>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card orange">
                    <div class="icon"><i class="fas fa-wallet"></i></div>
                    <h3 style="font-size:1.15rem;"><?= formatRupiah($statistik['tunai']) ?></h3>
                    <p>Pembayaran Tunai</p>
                    <span class="badge bg-warning text-dark">Cash Fisik</span>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- DETAIL TRANSAKSI -->
            <div class="col-lg-8 print-detail">
                <div class="table-container">
                    <h5 class="mb-3"><i class="fas fa-list me-2"></i>Detail Transaksi</h5>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No Transaksi</th>
                                    <?php if ($isOwner): ?><th>Cabang</th><?php endif; ?>
                                    <th>Tanggal</th>
                                    <th>Pelanggan</th>
                                    <th>Total</th>
                                    <th>Metode</th>
                                    <th>Kasir</th>
                                    <th class="no-print">Struk</th>
                                    <th class="no-print">Bukti</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transaksi)): ?>
                                    <tr>
                                        <td colspan="<?= $isOwner ? 9 : 8 ?>" class="text-center text-muted py-3">
                                            Tidak ada transaksi pada periode yang dipilih.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transaksi as $t): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars($t['no_transaksi']) ?></code></td>
                                            <?php if ($isOwner): ?>
                                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['Nama_cabang'] ?? 'Pusat') ?></span></td>
                                            <?php endif; ?>
                                            <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($t['nama_pelanggan']) ?></td>
                                            <td class="text-nowrap fw-bold"><?= formatRupiah($t['total_harga']) ?></td>
                                            <td>
                                                <?php
                                                $badge_class = ['Tunai' => 'bg-success', 'Transfer' => 'bg-primary', 'QRIS' => 'bg-info text-dark'];
                                                ?>
                                                <span class="badge <?= $badge_class[$t['metode_bayar']] ?? 'bg-secondary' ?>">
                                                    <?= $t['metode_bayar'] ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($t['nama_lengkap']) ?></td>
                                            <td class="no-print">
                                                <a href="<?= $isOwner ? '../kasir/transaksi.php' : 'transaksi.php' ?>?struk=<?= $t['id'] ?>" class="btn btn-info btn-sm text-white" title="Lihat Struk" target="_blank">
                                                    <i class="fas fa-receipt"></i>
                                                </a>
                                            </td>
                                            <td class="no-print">
                                                <?php if (!empty($t['bukti_transfer'])): ?>
                                                    <button type="button" class="btn btn-primary btn-sm btn-lihat-bukti"
                                                        data-bs-toggle="modal" data-bs-target="#modalBuktiTransfer"
                                                        data-img="<?= htmlspecialchars(buktiTransferUrl($t['bukti_transfer'])) ?>"
                                                        data-trx="<?= htmlspecialchars($t['no_transaksi']) ?>"
                                                        data-pelanggan="<?= htmlspecialchars($t['nama_pelanggan']) ?>"
                                                        title="Lihat Bukti">
                                                        <i class="fas fa-image"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($transaksi)): ?>
                                <tfoot class="table-secondary fw-bold">
                                    <tr>
                                        <td colspan="<?= $isOwner ? 4 : 3 ?>" class="text-end">TOTAL:</td>
                                        <td class="text-nowrap"><?= formatRupiah($statistik['total_pendapatan']) ?></td>
                                        <td colspan="4"></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- PRODUK TERLARIS -->
            <div class="col-lg-4 print-hide">
                <div class="table-container h-100">
                    <h5 class="mb-3"><i class="fas fa-trophy me-2 text-warning"></i>Produk Terlaris</h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Produk</th>
                                    <th>Terjual</th>
                                    <th>Nilai</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($produk_terlaris)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">Tidak ada data produk terjual.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($produk_terlaris as $index => $p): ?>
                                        <tr>
                                            <td>
                                                <?php if ($index < 3): ?>
                                                    <span class="badge bg-warning text-dark me-1"><?= $index + 1 ?></span>
                                                <?php endif; ?>
                                                <small><?= htmlspecialchars($p['nama_produk']) ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary"><?= $p['total_qty'] ?></span>
                                            </td>
                                            <td class="text-nowrap small fw-bold">
                                                <?= formatRupiah($p['total_nilai']) ?>
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

        <!-- RINGKASAN METODE PEMBAYARAN -->
        <div class="row print-hide">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Ringkasan Metode Pembayaran</h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="card border-success">
                                <div class="card-body">
                                    <h6 class="card-title text-success"><i class="fas fa-money-bill-wave me-2"></i>Tunai</h6>
                                    <h4><?= formatRupiah($statistik['tunai']) ?></h4>
                                    <small class="text-muted">
                                        <?php $persen_tunai = $statistik['total_pendapatan'] > 0 ? ($statistik['tunai'] / $statistik['total_pendapatan'] * 100) : 0; ?>
                                        <?= number_format($persen_tunai, 1) ?>% dari total pendapatan
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-primary">
                                <div class="card-body">
                                    <h6 class="card-title text-primary"><i class="fas fa-university me-2"></i>Transfer</h6>
                                    <h4><?= formatRupiah($statistik['transfer']) ?></h4>
                                    <small class="text-muted">
                                        <?php $persen_transfer = $statistik['total_pendapatan'] > 0 ? ($statistik['transfer'] / $statistik['total_pendapatan'] * 100) : 0; ?>
                                        <?= number_format($persen_transfer, 1) ?>% dari total pendapatan
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-info">
                                <div class="card-body">
                                    <h6 class="card-title text-info"><i class="fas fa-qrcode me-2"></i>QRIS</h6>
                                    <h4><?= formatRupiah($statistik['qris']) ?></h4>
                                    <small class="text-muted">
                                        <?php $persen_qris = $statistik['total_pendapatan'] > 0 ? ($statistik['qris'] / $statistik['total_pendapatan'] * 100) : 0; ?>
                                        <?= number_format($persen_qris, 1) ?>% dari total pendapatan
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- MODAL BUKTI TRANSFER -->
    <div class="modal fade" id="modalBuktiTransfer" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-image me-2"></i>Bukti Transfer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="text-start small text-muted mb-3">
                        <div>No Transaksi: <strong id="buktiNoTransaksi"></strong></div>
                        <div>Pelanggan: <strong id="buktiPelanggan"></strong></div>
                    </div>
                    <img src="" alt="Bukti transfer" class="img-fluid proof-modal-img" id="buktiTransferImage">
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../asset/script.js"></script>
    <script>
        document.querySelectorAll('.btn-cetak-laporan').forEach(button => {
            button.addEventListener('click', function() {
                const form = this.closest('form');
                const params = new URLSearchParams(new FormData(form));
                params.set('auto_print', '1');
                params.set('_ts', Date.now().toString());
                window.location.href = form.getAttribute('action') + '?' + params.toString();
            });
        });

        <?php if (isset($_GET['auto_print'])): ?>
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                    const url = new URL(window.location.href);
                    url.searchParams.delete('auto_print');
                    url.searchParams.delete('_ts');
                    history.replaceState(null, '', url.toString());
                }, 800);
            });
        <?php endif; ?>

        document.querySelectorAll('.btn-lihat-bukti').forEach(button => {
            button.addEventListener('click', function() {
                document.getElementById('buktiTransferImage').src = this.dataset.img;
                document.getElementById('buktiNoTransaksi').textContent = this.dataset.trx;
                document.getElementById('buktiPelanggan').textContent = this.dataset.pelanggan;
            });
        });
    </script>
</body>

</html>