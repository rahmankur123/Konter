<?php
require_once '../config.php';
checkRole(['pemilik']);

$conn = getConnection();
$pageTitle = "Kelola Master Produk";
$pesan = '';
$pesan_type = '';

$id_cabang_aktif = getActiveCabangId();

// Ambil daftar seluruh cabang untuk dropdown form & filter
$daftar_cabang = $conn->query("SELECT id, Nama_cabang FROM Cabang ORDER BY Nama_cabang ASC")->fetch_all(MYSQLI_ASSOC);

// ============================================================
// 1. PROSES TAMBAH PRODUK
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'tambah') {
    $kode       = trim($_POST['kode_produk'] ?? '');
    $nama       = trim($_POST['nama_produk'] ?? '');
    $id_cabang  = intval($_POST['id_cabang'] ?? 0);
    $kategori   = $_POST['kategori'] ?? '';
    $merk       = trim($_POST['merk'] ?? '');
    $harga_beli = floatval($_POST['harga_beli'] ?? 0);
    $harga_jual = floatval($_POST['harga_jual'] ?? 0);
    $stok       = intval($_POST['stok'] ?? 0);
    $satuan     = $_POST['satuan'] ?? 'Unit';
    $status     = $_POST['status'] ?? 'Aktif';
    $deskripsi  = trim($_POST['deskripsi'] ?? '');

    $valid_kategori = ['Aksesoris HP', 'Voucher Internet', 'Layanan Saldo'];
    $valid_satuan   = ['Unit', 'Pcs', 'Box'];

    if (!$kode || !$nama || !$kategori || $id_cabang <= 0) {
        $pesan = 'Kode produk, nama produk, kategori, dan cabang penempatan wajib diisi!';
        $pesan_type = 'danger';
    } elseif (!in_array($kategori, $valid_kategori)) {
        $pesan = 'Kategori produk tidak valid!';
        $pesan_type = 'danger';
    } else {
        // Cek duplikat kode pada cabang yang sama
        $cek = $conn->prepare("SELECT id FROM produk WHERE kode_produk = ? AND Id_cabang = ?");
        $cek->bind_param("si", $kode, $id_cabang);
        $cek->execute();
        $cek->store_result();
        if ($cek->num_rows > 0) {
            $pesan = 'Kode produk sudah digunakan pada cabang tersebut!';
            $pesan_type = 'danger';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO produk (kode_produk, nama_produk, Id_cabang, kategori, merk, harga_beli, harga_jual, stok, satuan, status, deskripsi) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("ssissddisss", $kode, $nama, $id_cabang, $kategori, $merk, $harga_beli, $harga_jual, $stok, $satuan, $status, $deskripsi);
            if ($stmt->execute()) {
                $pesan = 'Produk baru berhasil ditambahkan!';
                $pesan_type = 'success';
            } else {
                $pesan = 'Gagal menyimpan produk: ' . $conn->error;
                $pesan_type = 'danger';
            }
            $stmt->close();
        }
        $cek->close();
    }
}

// ============================================================
// 2. PROSES EDIT PRODUK
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id         = intval($_POST['id'] ?? 0);
    $kode       = trim($_POST['kode_produk'] ?? '');
    $nama       = trim($_POST['nama_produk'] ?? '');
    $id_cabang  = intval($_POST['id_cabang'] ?? 0);
    $kategori   = $_POST['kategori'] ?? '';
    $merk       = trim($_POST['merk'] ?? '');
    $harga_beli = floatval($_POST['harga_beli'] ?? 0);
    $harga_jual = floatval($_POST['harga_jual'] ?? 0);
    $stok       = intval($_POST['stok'] ?? 0);
    $satuan     = $_POST['satuan'] ?? 'Unit';
    $status     = $_POST['status'] ?? 'Aktif';
    $deskripsi  = trim($_POST['deskripsi'] ?? '');

    $valid_kategori = ['Aksesoris HP', 'Voucher Internet', 'Layanan Saldo'];

    if (!$id || !$kode || !$nama || !$kategori || $id_cabang <= 0) {
        $pesan = 'Data produk tidak lengkap!';
        $pesan_type = 'danger';
    } elseif (!in_array($kategori, $valid_kategori)) {
        $pesan = 'Kategori produk tidak valid!';
        $pesan_type = 'danger';
    } else {
        $cek = $conn->prepare("SELECT id FROM produk WHERE kode_produk = ? AND Id_cabang = ? AND id != ?");
        $cek->bind_param("sii", $kode, $id_cabang, $id);
        $cek->execute();
        $cek->store_result();
        if ($cek->num_rows > 0) {
            $pesan = 'Kode produk sudah digunakan produk lain di cabang tersebut!';
            $pesan_type = 'danger';
        } else {
            $stmt = $conn->prepare(
                "UPDATE produk 
                 SET kode_produk=?, nama_produk=?, Id_cabang=?, kategori=?, merk=?, harga_beli=?, harga_jual=?, stok=?, satuan=?, status=?, deskripsi=? 
                 WHERE id=?"
            );
            $stmt->bind_param("ssissddisssi", $kode, $nama, $id_cabang, $kategori, $merk, $harga_beli, $harga_jual, $stok, $satuan, $status, $deskripsi, $id);
            if ($stmt->execute()) {
                $pesan = 'Data produk berhasil diperbarui!';
                $pesan_type = 'success';
            } else {
                $pesan = 'Gagal memperbarui produk: ' . $conn->error;
                $pesan_type = 'danger';
            }
            $stmt->close();
        }
        $cek->close();
    }
}

// ============================================================
// 3. PROSES HAPUS PRODUK
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'hapus') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM produk WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $pesan = 'Produk berhasil dihapus!';
            $pesan_type = 'success';
        } else {
            $pesan = 'Gagal menghapus produk karena terikat dengan riwayat transaksi!';
            $pesan_type = 'danger';
        }
        $stmt->close();
    }
}

// ============================================================
// 4. QUERY DATA TABEL & FILTER
// ============================================================
$search          = trim($_GET['search'] ?? '');
$filter_cabang   = $_GET['filter_cabang'] ?? $id_cabang_aktif;
$filter_kategori = $_GET['filter_kategori'] ?? '';
$filter_status   = $_GET['filter_status'] ?? '';
$filter_stok     = $_GET['filter_stok'] ?? '';

$where  = ["1=1"];
$params = [];
$types  = "";

if ($filter_cabang && intval($filter_cabang) > 0) {
    $where[]  = "p.Id_cabang = ?";
    $params[] = intval($filter_cabang);
    $types   .= "i";
}
if ($search !== '') {
    $like     = "%$search%";
    $where[]  = "(p.kode_produk LIKE ? OR p.nama_produk LIKE ? OR p.merk LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}
if ($filter_kategori !== '') {
    $where[]  = "p.kategori = ?";
    $params[] = $filter_kategori;
    $types   .= "s";
}
if ($filter_status !== '') {
    $where[]  = "p.status = ?";
    $params[] = $filter_status;
    $types   .= "s";
}
if ($filter_stok === 'tersedia') {
    $where[] = "p.stok >= 10";
} elseif ($filter_stok === 'menipis') {
    $where[] = "p.stok > 0 AND p.stok < 10";
} elseif ($filter_stok === 'habis') {
    $where[] = "p.stok = 0";
}

$where_clause = implode(' AND ', $where);

// Ambil data produk
$sql  = "SELECT p.*, c.Nama_cabang 
         FROM produk p 
         LEFT JOIN Cabang c ON p.Id_cabang = c.id 
         WHERE $where_clause 
         ORDER BY c.Nama_cabang ASC, p.nama_produk ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$produks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ============================================================
// 5. HITUNG STATISTIK TOTAL ASET (BERDASARKAN FILTER CABANG)
// ============================================================
$stat_where = "1=1";
$stat_params = [];
$stat_types = "";
if ($filter_cabang && intval($filter_cabang) > 0) {
    $stat_where .= " AND Id_cabang = ?";
    $stat_params[] = intval($filter_cabang);
    $stat_types .= "i";
}

$sql_stat = "SELECT 
    COUNT(*) AS total_produk,
    COALESCE(SUM(stok), 0) AS total_fisik_stok,
    COALESCE(SUM(harga_beli * stok), 0) AS total_aset_modal,
    COALESCE(SUM(harga_jual * stok), 0) AS total_nilai_jual,
    SUM(stok > 0 AND stok < 10) AS stok_menipis,
    SUM(stok = 0) AS stok_habis
    FROM produk 
    WHERE $stat_where";

$stmt_stat = $conn->prepare($sql_stat);
if (!empty($stat_params)) {
    $stmt_stat->bind_param($stat_types, ...$stat_params);
}
$stmt_stat->execute();
$stat_aset = $stmt_stat->get_result()->fetch_assoc();
$stmt_stat->close();

// ============================================================
// 6. AMBIL DATA MODAL (DETAIL & EDIT)
// ============================================================
$detail_produk = null;
if (!empty($_GET['detail_id']) && (int)$_GET['detail_id'] > 0) {
    $did = (int)$_GET['detail_id'];
    $dstmt = $conn->prepare("SELECT p.*, c.Nama_cabang FROM produk p LEFT JOIN Cabang c ON p.Id_cabang = c.id WHERE p.id = ?");
    $dstmt->bind_param("i", $did);
    $dstmt->execute();
    $detail_produk = $dstmt->get_result()->fetch_assoc();
    $dstmt->close();
}

$edit_produk = null;
if (!empty($_GET['edit_id']) && (int)$_GET['edit_id'] > 0) {
    $eid = (int)$_GET['edit_id'];
    $estmt = $conn->prepare("SELECT * FROM produk WHERE id = ?");
    $estmt->bind_param("i", $eid);
    $estmt->execute();
    $edit_produk = $estmt->get_result()->fetch_assoc();
    $estmt->close();
}

function qs($search, $filter_cabang, $filter_kategori, $filter_status, $filter_stok) {
    return http_build_query(array_filter([
        'search'          => $search,
        'filter_cabang'   => $filter_cabang,
        'filter_kategori' => $filter_kategori,
        'filter_status'   => $filter_status,
        'filter_stok'     => $filter_stok,
    ]));
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
</head>

<body class="kasir-modern mobile-card-tables">

    <?php include '../layout/sidebar.php'; ?>
    <?php include '../layout/navbar.php'; ?>
    <div class="overlay" id="overlay"></div>

    <main class="main-content">
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="fw-bold mb-1">Master Data & Nilai Aset Produk</h2>
                <p class="text-muted mb-0">Kelola inventaris dan pantau perputaran modal aset produk di seluruh cabang</p>
            </div>
            <div>
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambahProduk">
                    <i class="fas fa-plus-circle me-2"></i>Tambah Produk Baru
                </button>
            </div>
        </div>

        <?php if ($pesan): ?>
            <div class="alert alert-<?= $pesan_type ?> alert-dismissible fade show" role="alert">
                <?= $pesan ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- KARTU RINGKASAN TOTAL ASET -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card purple">
                    <div class="icon"><i class="fas fa-coins"></i></div>
                    <h3 style="font-size:1.25rem;"><?= formatRupiah($stat_aset['total_aset_modal']) ?></h3>
                    <p>Total Nilai Aset (Modal)</p>
                    <span class="badge bg-dark">&sum; (Harga Modal &times; Stok)</span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card green">
                    <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
                    <h3 style="font-size:1.25rem;"><?= formatRupiah($stat_aset['total_nilai_jual']) ?></h3>
                    <p>Estimasi Nilai Jual</p>
                    <span class="badge bg-success">&sum; (Harga Jual &times; Stok)</span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card blue">
                    <div class="icon"><i class="fas fa-boxes"></i></div>
                    <h3><?= number_format($stat_aset['total_fisik_stok']) ?> Item</h3>
                    <p>Total Fisik Stok</p>
                    <span class="badge bg-primary"><?= number_format($stat_aset['total_produk']) ?> Varian Produk</span>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="stats-card orange">
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3><?= number_format($stat_aset['stok_menipis']) ?> Produk</h3>
                    <p>Stok Menipis (&lt;10)</p>
                    <span class="badge bg-warning text-dark">Kosong: <?= number_format($stat_aset['stok_habis']) ?></span>
                </div>
            </div>
        </div>

        <!-- PRODUCT TABLE -->
        <div class="row">
            <div class="col-12">
                <div class="table-container">
                    <!-- Form Filter & Pencarian -->
                    <form method="GET" action="">
                        <div class="row mb-3 g-2">
                            <div class="col-md-3">
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" name="search"
                                        value="<?= htmlspecialchars($search) ?>"
                                        placeholder="Cari nama, barcode, merk...">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="filter_cabang" onchange="this.form.submit()">
                                    <option value="">Semua Cabang</option>
                                    <?php foreach ($daftar_cabang as $cb): ?>
                                        <option value="<?= $cb['id'] ?>" <?= $filter_cabang == $cb['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cb['Nama_cabang']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="filter_kategori" onchange="this.form.submit()">
                                    <option value="">Semua Kategori</option>
                                    <option value="Aksesoris HP" <?= $filter_kategori === 'Aksesoris HP' ? 'selected' : '' ?>>Aksesoris HP</option>
                                    <option value="Voucher Internet" <?= $filter_kategori === 'Voucher Internet' ? 'selected' : '' ?>>Voucher Internet</option>
                                    <option value="Layanan Saldo" <?= $filter_kategori === 'Layanan Saldo' ? 'selected' : '' ?>>Layanan Saldo</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="filter_stok" onchange="this.form.submit()">
                                    <option value="">Semua Status Stok</option>
                                    <option value="tersedia" <?= $filter_stok === 'tersedia' ? 'selected' : '' ?>>Tersedia (&ge;10)</option>
                                    <option value="menipis" <?= $filter_stok === 'menipis' ? 'selected' : '' ?>>Menipis (&lt;10)</option>
                                    <option value="habis" <?= $filter_stok === 'habis' ? 'selected' : '' ?>>Habis (0)</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-1">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i></button>
                                <a href="data-produk.php" class="btn btn-secondary w-100"><i class="fas fa-undo"></i></a>
                            </div>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="produkTable">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>Kode Barcode</th>
                                    <th>Nama Produk</th>
                                    <th>Cabang</th>
                                    <th>Kategori</th>
                                    <th>Modal Beli</th>
                                    <th>Harga Jual</th>
                                    <th>Stok</th>
                                    <th>Subtotal Aset</th>
                                    <th>Status</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($produks)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center text-muted py-4">
                                            <i class="fas fa-box-open fa-2x mb-2 d-block"></i>
                                            Tidak ada data produk yang sesuai.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($produks as $i => $p): ?>
                                        <?php 
                                        $q = qs($search, $filter_cabang, $filter_kategori, $filter_status, $filter_stok); 
                                        $subtotal_aset = $p['harga_beli'] * $p['stok'];
                                        ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><code><?= htmlspecialchars($p['kode_produk']) ?></code></td>
                                            <td>
                                                <strong><?= htmlspecialchars($p['nama_produk']) ?></strong>
                                                <?php if ($p['merk']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($p['merk']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['Nama_cabang'] ?? 'Pusat') ?></span></td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($p['kategori']) ?></span></td>
                                            <td><?= formatRupiah($p['harga_beli']) ?></td>
                                            <td class="fw-bold text-primary"><?= formatRupiah($p['harga_jual']) ?></td>
                                            <td>
                                                <?php if ($p['kategori'] === 'Layanan Saldo'): ?>
                                                    <span class="badge bg-info text-dark">Non-Fisik</span>
                                                <?php elseif ($p['stok'] == 0): ?>
                                                    <span class="badge bg-danger">0 <?= $p['satuan'] ?></span>
                                                <?php elseif ($p['stok'] < 10): ?>
                                                    <span class="badge bg-warning text-dark"><?= $p['stok'] ?> <?= $p['satuan'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><?= $p['stok'] ?> <?= $p['satuan'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-success"><?= formatRupiah($subtotal_aset) ?></td>
                                            <td>
                                                <span class="badge <?= $p['status'] === 'Aktif' ? 'bg-success' : 'bg-danger' ?>">
                                                    <?= htmlspecialchars($p['status']) ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="data-produk.php?detail_id=<?= $p['id'] ?>&<?= $q ?>" class="btn btn-info text-white" title="Detail"><i class="fas fa-eye"></i></a>
                                                    <a href="data-produk.php?edit_id=<?= $p['id'] ?>&<?= $q ?>" class="btn btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalHapus<?= $p['id'] ?>" title="Hapus"><i class="fas fa-trash"></i></button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- MODAL HAPUS -->
                                        <div class="modal fade" id="modalHapus<?= $p['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-sm modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-danger text-white">
                                                        <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Hapus Produk</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        Hapus <strong><?= htmlspecialchars($p['nama_produk']) ?></strong> (Cabang: <?= htmlspecialchars($p['Nama_cabang'] ?? 'Pusat') ?>)?
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                        <form method="POST" action="data-produk.php">
                                                            <input type="hidden" name="action" value="hapus">
                                                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <small class="text-muted">Total master produk: <?= count($produks) ?></small>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ========== MODAL TAMBAH PRODUK ========== -->
    <div class="modal fade" id="modalTambahProduk" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Tambah Produk Baru</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="data-produk.php">
                    <input type="hidden" name="action" value="tambah">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cabang Penempatan <span class="text-danger">*</span></label>
                                <select class="form-select" name="id_cabang" required>
                                    <option value="">-- Pilih Cabang --</option>
                                    <?php foreach ($daftar_cabang as $cb): ?>
                                        <option value="<?= $cb['id'] ?>"><?= htmlspecialchars($cb['Nama_cabang']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Kode Barcode / SKU <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="kode_produk" placeholder="Contoh: ACC001" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Nama Produk <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_produk" placeholder="Contoh: Kabel Data Type-C" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Kategori <span class="text-danger">*</span></label>
                                <select class="form-select" name="kategori" required>
                                    <option value="">Pilih Kategori</option>
                                    <option value="Aksesoris HP">Aksesoris HP</option>
                                    <option value="Voucher Internet">Voucher Internet</option>
                                    <option value="Layanan Saldo">Layanan Saldo (Top-up / Transfer)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Merk (Opsional)</label>
                                <input type="text" class="form-control" name="merk" placeholder="ROBOT, V-Gen, Telkomsel">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Harga Beli / Modal <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="harga_beli" min="0" placeholder="0" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Harga Jual <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="harga_jual" min="0" placeholder="0" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Stok Awal</label>
                                <input type="number" class="form-control" name="stok" min="0" value="0" placeholder="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Satuan</label>
                                <select class="form-select" name="satuan">
                                    <option value="Unit">Unit</option>
                                    <option value="Pcs">Pcs</option>
                                    <option value="Box">Box</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status Produk</label>
                                <select class="form-select" name="status">
                                    <option value="Aktif">Aktif</option>
                                    <option value="Nonaktif">Nonaktif</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea class="form-control" name="deskripsi" rows="2" placeholder="Keterangan spesifikasi produk (opsional)"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Simpan Produk</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== MODAL EDIT PRODUK ========== -->
    <div class="modal fade" id="modalEditProduk" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Data Produk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="data-produk.php">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?= $edit_produk['id'] ?? '' ?>">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cabang Penempatan <span class="text-danger">*</span></label>
                                <select class="form-select" name="id_cabang" required>
                                    <?php foreach ($daftar_cabang as $cb): ?>
                                        <option value="<?= $cb['id'] ?>" <?= ($edit_produk['Id_cabang'] ?? '') == $cb['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cb['Nama_cabang']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Kode Barcode / SKU <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="kode_produk" value="<?= htmlspecialchars($edit_produk['kode_produk'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Nama Produk <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_produk" value="<?= htmlspecialchars($edit_produk['nama_produk'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Kategori <span class="text-danger">*</span></label>
                                <select class="form-select" name="kategori" required>
                                    <option value="Aksesoris HP" <?= ($edit_produk['kategori'] ?? '') === 'Aksesoris HP' ? 'selected' : '' ?>>Aksesoris HP</option>
                                    <option value="Voucher Internet" <?= ($edit_produk['kategori'] ?? '') === 'Voucher Internet' ? 'selected' : '' ?>>Voucher Internet</option>
                                    <option value="Layanan Saldo" <?= ($edit_produk['kategori'] ?? '') === 'Layanan Saldo' ? 'selected' : '' ?>>Layanan Saldo</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Merk</label>
                                <input type="text" class="form-control" name="merk" value="<?= htmlspecialchars($edit_produk['merk'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Harga Beli / Modal <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="harga_beli" min="0" value="<?= $edit_produk['harga_beli'] ?? 0 ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Harga Jual <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="harga_jual" min="0" value="<?= $edit_produk['harga_jual'] ?? 0 ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Stok</label>
                                <input type="number" class="form-control" name="stok" min="0" value="<?= $edit_produk['stok'] ?? 0 ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Satuan</label>
                                <select class="form-select" name="satuan">
                                    <option value="Unit" <?= ($edit_produk['satuan'] ?? '') === 'Unit' ? 'selected' : '' ?>>Unit</option>
                                    <option value="Pcs" <?= ($edit_produk['satuan'] ?? '') === 'Pcs' ? 'selected' : '' ?>>Pcs</option>
                                    <option value="Box" <?= ($edit_produk['satuan'] ?? '') === 'Box' ? 'selected' : '' ?>>Box</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="Aktif" <?= ($edit_produk['status'] ?? '') === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                    <option value="Nonaktif" <?= ($edit_produk['status'] ?? '') === 'Nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea class="form-control" name="deskripsi" rows="2"><?= htmlspecialchars($edit_produk['deskripsi'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="data-produk.php?<?= qs($search, $filter_cabang, $filter_kategori, $filter_status, $filter_stok) ?>" class="btn btn-secondary">Batal</a>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i>Update Produk</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== MODAL DETAIL PRODUK ========== -->
    <div class="modal fade" id="modalDetailProduk" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Detail Produk</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($detail_produk): ?>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Cabang:</div>
                            <div class="col-8"><span class="badge bg-light text-dark border"><?= htmlspecialchars($detail_produk['Nama_cabang'] ?? 'Pusat') ?></span></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Barcode/SKU:</div>
                            <div class="col-8"><code><?= htmlspecialchars($detail_produk['kode_produk']) ?></code></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Nama Produk:</div>
                            <div class="col-8"><?= htmlspecialchars($detail_produk['nama_produk']) ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Kategori:</div>
                            <div class="col-8"><span class="badge bg-secondary"><?= htmlspecialchars($detail_produk['kategori']) ?></span></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Harga Modal:</div>
                            <div class="col-8"><?= formatRupiah($detail_produk['harga_beli']) ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Harga Jual:</div>
                            <div class="col-8 text-primary fw-bold"><?= formatRupiah($detail_produk['harga_jual']) ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Stok Tersedia:</div>
                            <div class="col-8"><strong><?= $detail_produk['stok'] ?> <?= htmlspecialchars($detail_produk['satuan']) ?></strong></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Nilai Aset:</div>
                            <div class="col-8 text-success fw-bold"><?= formatRupiah($detail_produk['harga_beli'] * $detail_produk['stok']) ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Status:</div>
                            <div class="col-8"><span class="badge <?= $detail_produk['status'] === 'Aktif' ? 'bg-success' : 'bg-danger' ?>"><?= htmlspecialchars($detail_produk['status']) ?></span></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Deskripsi:</div>
                            <div class="col-8"><?= nl2br(htmlspecialchars($detail_produk['deskripsi'] ?: '-')) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <a href="data-produk.php?<?= qs($search, $filter_cabang, $filter_kategori, $filter_status, $filter_stok) ?>" class="btn btn-secondary">Tutup</a>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== SCRIPTS ========== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../asset/script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($detail_produk): ?>
                new bootstrap.Modal(document.getElementById('modalDetailProduk')).show();
            <?php endif; ?>
            <?php if ($edit_produk): ?>
                new bootstrap.Modal(document.getElementById('modalEditProduk')).show();
            <?php endif; ?>
        });
    </script>
</body>

</html>