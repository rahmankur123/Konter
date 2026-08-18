<?php
require_once '../config.php';
checkRole(['kasir']);

$conn = getConnection();
$pageTitle = "Data Produk Cabang";
$pesan = '';
$pesan_type = '';

$id_cabang = getActiveCabangId();

if (!$id_cabang) {
    die("Akses ditolak: Akun kasir belum terhubung ke cabang manapun.");
}

// ============================================================
// PROSES UPDATE STOK OLEH KASIR
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'update_stok') {
    $id_produk    = intval($_POST['id_produk'] ?? 0);
    $tipe_update  = $_POST['tipe_update'] ?? 'tambah'; // 'tambah' (restock) atau 'set' (penyesuaian manual)
    $jumlah_stok  = intval($_POST['jumlah_stok'] ?? 0);

    if ($id_produk <= 0 || $jumlah_stok < 0) {
        $pesan = 'Data produk atau jumlah stok tidak valid!';
        $pesan_type = 'danger';
    } else {
        // Cek apakah produk benar milik cabang kasir
        $cek = $conn->prepare("SELECT id, stok, nama_produk FROM produk WHERE id = ? AND Id_cabang = ?");
        $cek->bind_param("ii", $id_produk, $id_cabang);
        $cek->execute();
        $prod = $cek->get_result()->fetch_assoc();
        $cek->close();

        if (!$prod) {
            $pesan = 'Produk tidak ditemukan di cabang ini!';
            $pesan_type = 'danger';
        } else {
            $stok_baru = ($tipe_update === 'tambah') ? ($prod['stok'] + $jumlah_stok) : $jumlah_stok;

            $stmt = $conn->prepare("UPDATE produk SET stok = ?, updated_at = NOW() WHERE id = ? AND Id_cabang = ?");
            $stmt->bind_param("iii", $stok_baru, $id_produk, $id_cabang);
            
            if ($stmt->execute()) {
                $pesan = "Stok untuk produk <strong>" . htmlspecialchars($prod['nama_produk']) . "</strong> berhasil diperbarui menjadi <strong>{$stok_baru}</strong>!";
                $pesan_type = 'success';
            } else {
                $pesan = 'Gagal memperbarui stok: ' . $conn->error;
                $pesan_type = 'danger';
            }
            $stmt->close();
        }
    }
}

// ============================================================
// AMBIL DATA PRODUK UNTUK TABEL (KHUSUS CABANG KASIR)
// ============================================================
$search          = trim($_GET['search'] ?? '');
$filter_kategori = $_GET['filter_kategori'] ?? '';
$filter_status   = $_GET['filter_status'] ?? '';
$filter_stok     = $_GET['filter_stok'] ?? '';

$where  = "WHERE Id_cabang = ?";
$params = [$id_cabang];
$types  = "i";

if ($search !== '') {
    $like     = "%$search%";
    $where   .= " AND (kode_produk LIKE ? OR nama_produk LIKE ? OR merk LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}
if ($filter_kategori !== '') {
    $where   .= " AND kategori = ?";
    $params[] = $filter_kategori;
    $types   .= "s";
}
if ($filter_status !== '') {
    $where   .= " AND status = ?";
    $params[] = $filter_status;
    $types   .= "s";
}
if ($filter_stok === 'tersedia') {
    $where .= " AND stok >= 10";
} elseif ($filter_stok === 'menipis') {
    $where .= " AND stok > 0 AND stok < 10";
} elseif ($filter_stok === 'habis') {
    $where .= " AND stok = 0";
}

$sql  = "SELECT * FROM produk $where ORDER BY nama_produk ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$produks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ============================================================
// AMBIL DATA UNTUK MODAL DETAIL
// ============================================================
$detail_produk = null;
if (!empty($_GET['detail_id']) && (int)$_GET['detail_id'] > 0) {
    $did = (int)$_GET['detail_id'];
    $dstmt = $conn->prepare("SELECT * FROM produk WHERE id = ? AND Id_cabang = ?");
    $dstmt->bind_param("ii", $did, $id_cabang);
    $dstmt->execute();
    $detail_produk = $dstmt->get_result()->fetch_assoc();
    $dstmt->close();
}

function qs($search, $filter_kategori, $filter_status, $filter_stok) {
    return http_build_query(array_filter([
        'search'          => $search,
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
    <title>Stok Produk - Fedly Cell POS</title>
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
                <h2 class="fw-bold mb-1">Stok & Data Produk</h2>
                <p class="text-muted mb-0">Pantau ketersediaan dan perbarui stok produk cabang <strong><?= htmlspecialchars($_SESSION['nama_cabang'] ?? '') ?></strong></p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUpdateStokCepat">
                    <i class="fas fa-boxes me-2"></i>Update Stok Masuk
                </button>
            </div>
        </div>

        <?php if ($pesan): ?>
            <div class="alert alert-<?= $pesan_type ?> alert-dismissible fade show" role="alert">
                <?= $pesan ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="table-container">
                    <form method="GET" action="">
                        <div class="row mb-3 g-2">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" name="search"
                                        value="<?= htmlspecialchars($search) ?>"
                                        placeholder="Cari produk, kode barcode, merk...">
                                </div>
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
                                <select class="form-select" name="filter_status" onchange="this.form.submit()">
                                    <option value="">Semua Status</option>
                                    <option value="Aktif" <?= $filter_status === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                    <option value="Nonaktif" <?= $filter_status === 'Nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="filter_stok" onchange="this.form.submit()">
                                    <option value="">Semua Stok</option>
                                    <option value="tersedia" <?= $filter_stok === 'tersedia' ? 'selected' : '' ?>>Tersedia (&ge;10)</option>
                                    <option value="menipis" <?= $filter_stok === 'menipis' ? 'selected' : '' ?>>Menipis (&lt;10)</option>
                                    <option value="habis" <?= $filter_stok === 'habis' ? 'selected' : '' ?>>Habis (0)</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i>Filter</button>
                                <a href="data-produk.php" class="btn btn-secondary w-100"><i class="fas fa-undo me-1"></i>Reset</a>
                            </div>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="produkTable">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>Kode / Barcode</th>
                                    <th>Nama Produk</th>
                                    <th>Kategori</th>
                                    <th>Harga Jual</th>
                                    <th>Sisa Stok</th>
                                    <th>Status</th>
                                    <th class="text-center">Aksi Kasir</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($produks)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <i class="fas fa-box-open fa-2x mb-2 d-block"></i>
                                            Tidak ada data produk yang cocok di cabang ini.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($produks as $i => $p): ?>
                                        <?php $q = qs($search, $filter_kategori, $filter_status, $filter_stok); ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><code><?= htmlspecialchars($p['kode_produk']) ?></code></td>
                                            <td>
                                                <strong><?= htmlspecialchars($p['nama_produk']) ?></strong>
                                                <?php if ($p['merk']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($p['merk']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($p['kategori']) ?></span></td>
                                            <td><strong><?= formatRupiah($p['harga_jual']) ?></strong></td>
                                            <td>
                                                <?php if ($p['kategori'] === 'Layanan Saldo'): ?>
                                                    <span class="badge bg-info text-dark">Non-Fisik</span>
                                                <?php elseif ($p['stok'] == 0): ?>
                                                    <span class="badge bg-danger">Habis (0 <?= $p['satuan'] ?>)</span>
                                                <?php elseif ($p['stok'] < 10): ?>
                                                    <span class="badge bg-warning text-dark"><?= $p['stok'] ?> <?= $p['satuan'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><?= $p['stok'] ?> <?= $p['satuan'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($p['status'] === 'Aktif'): ?>
                                                    <span class="badge bg-success">Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Nonaktif</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <a href="data-produk.php?detail_id=<?= $p['id'] ?>&<?= $q ?>"
                                                    class="btn btn-info btn-sm text-white" title="Lihat Detail">
                                                    <i class="fas fa-eye"></i>
                                                </a>

                                                <?php if ($p['kategori'] !== 'Layanan Saldo'): ?>
                                                    <button type="button" class="btn btn-primary btn-sm" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#modalUpdateStokItem<?= $p['id'] ?>" 
                                                            title="Update Stok">
                                                        <i class="fas fa-edit me-1"></i>Stok
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>

                                        <div class="modal fade" id="modalUpdateStokItem<?= $p['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title"><i class="fas fa-boxes me-2"></i>Update Stok: <?= htmlspecialchars($p['nama_produk']) ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="data-produk.php">
                                                        <input type="hidden" name="action" value="update_stok">
                                                        <input type="hidden" name="id_produk" value="<?= $p['id'] ?>">
                                                        <div class="modal-body">
                                                            <div class="alert alert-light border">
                                                                <div class="d-flex justify-content-between">
                                                                    <span>Stok Saat Ini:</span>
                                                                    <strong><?= $p['stok'] ?> <?= $p['satuan'] ?></strong>
                                                                </div>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Jenis Perubahan</label>
                                                                <div class="d-flex gap-3">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="radio" name="tipe_update" id="tipeTambah<?= $p['id'] ?>" value="tambah" checked>
                                                                        <label class="form-check-label" for="tipeTambah<?= $p['id'] ?>">
                                                                            Tambah Stok Masuk (+)
                                                                        </label>
                                                                    </div>
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="radio" name="tipe_update" id="tipeSet<?= $p['id'] ?>" value="set">
                                                                        <label class="form-check-label" for="tipeSet<?= $p['id'] ?>">
                                                                            Set Total Stok Baru (=)
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Jumlah (<?= $p['satuan'] ?>)</label>
                                                                <input type="number" class="form-control" name="jumlah_stok" min="0" placeholder="Masukkan jumlah stok" required>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-primary">Simpan Stok</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <small class="text-muted">Total produk aktif di cabang ini: <?= count($produks) ?></small>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalUpdateStokCepat" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Update Stok Masuk (Restock)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="data-produk.php">
                    <input type="hidden" name="action" value="update_stok">
                    <input type="hidden" name="tipe_update" value="tambah">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Pilih Produk <span class="text-danger">*</span></label>
                            <select class="form-select" name="id_produk" required>
                                <option value="">-- Pilih Produk Fisik --</option>
                                <?php foreach ($produks as $p): ?>
                                    <?php if ($p['kategori'] !== 'Layanan Saldo'): ?>
                                        <option value="<?= $p['id'] ?>">
                                            <?= htmlspecialchars($p['nama_produk']) ?> (Stok saat ini: <?= $p['stok'] ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Jumlah Stok Masuk (+) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="jumlah_stok" min="1" placeholder="Contoh: 10" required>
                            <small class="text-muted">Stok ini akan otomatis ditambahkan ke stok berjalan.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Tambahkan Stok</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalDetailProduk" tabindex="-1" aria-labelledby="modalDetailProdukLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="modalDetailProdukLabel"><i class="fas fa-info-circle me-2"></i>Detail Produk</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($detail_produk): ?>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Kode / Barcode:</div>
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
                            <div class="col-4 fw-bold">Merk:</div>
                            <div class="col-8"><?= htmlspecialchars($detail_produk['merk'] ?: '-') ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Harga Jual:</div>
                            <div class="col-8 text-primary fw-bold"><?= formatRupiah($detail_produk['harga_jual']) ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Stok:</div>
                            <div class="col-8">
                                <strong><?= $detail_produk['stok'] ?> <?= htmlspecialchars($detail_produk['satuan']) ?></strong>
                            </div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Status:</div>
                            <div class="col-8">
                                <span class="badge <?= $detail_produk['status'] === 'Aktif' ? 'bg-success' : 'bg-danger' ?>">
                                    <?= htmlspecialchars($detail_produk['status']) ?>
                                </span>
                            </div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 fw-bold">Deskripsi:</div>
                            <div class="col-8"><?= nl2br(htmlspecialchars($detail_produk['deskripsi'] ?: '-')) ?></div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center py-3">Data tidak ditemukan.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <a href="data-produk.php?<?= qs($search, $filter_kategori, $filter_status, $filter_stok) ?>" class="btn btn-secondary">Tutup</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../asset/script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($detail_produk): ?>
                new bootstrap.Modal(document.getElementById('modalDetailProduk')).show();
            <?php endif; ?>
        });
    </script>
</body>

</html>