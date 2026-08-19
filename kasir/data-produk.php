<?php
require_once '../config.php';
checkRole(['kasir', 'pemilik']);

$conn = getConnection();
$pageTitle = "Stok Produk Cabang";
$pesan = '';
$pesan_type = '';

$id_cabang = getActiveCabangId();

if (!$id_cabang && $_SESSION['role'] === 'kasir') {
    die("Akses ditolak: Akun kasir belum terhubung ke cabang manapun.");
}

// ============================================================
// PROSES UPDATE STOK / RESTOCK OLEH KASIR
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'tambah_stok') {
    $produk_id   = intval($_POST['produk_id'] ?? 0);
    $jumlah_masuk = intval($_POST['jumlah_masuk'] ?? 0);
    $keterangan   = trim($_POST['keterangan'] ?? '');

    if ($produk_id <= 0 || $jumlah_masuk <= 0) {
        $pesan = 'Jumlah stok masuk harus lebih dari 0!';
        $pesan_type = 'danger';
    } else {
        // Pastikan produk milik cabang kasir
        $cek = $conn->prepare("SELECT id, nama_produk, stok FROM produk WHERE id = ? " . ($id_cabang ? "AND Id_cabang = ?" : ""));
        if ($id_cabang) {
            $cek->bind_param("ii", $produk_id, $id_cabang);
        } else {
            $cek->bind_param("i", $produk_id);
        }
        $cek->execute();
        $prod = $cek->get_result()->fetch_assoc();
        $cek->close();

        if (!$prod) {
            $pesan = 'Produk tidak ditemukan di cabang ini!';
            $pesan_type = 'danger';
        } else {
            $stmt = $conn->prepare("UPDATE produk SET stok = stok + ? WHERE id = ?");
            $stmt->bind_param("ii", $jumlah_masuk, $produk_id);
            if ($stmt->execute()) {
                $pesan = "Stok <strong>" . htmlspecialchars($prod['nama_produk']) . "</strong> berhasil ditambah +$jumlah_masuk unit!";
                $pesan_type = 'success';
            } else {
                $pesan = 'Gagal menambah stok: ' . $conn->error;
                $pesan_type = 'danger';
            }
            $stmt->close();
        }
    }
}

// ============================================================
// AMBIL DATA PRODUK CABANG
// ============================================================
$search          = trim($_GET['search'] ?? '');
$filter_kategori = $_GET['filter_kategori'] ?? '';
$filter_stok     = $_GET['filter_stok'] ?? '';

$where  = ["1=1"];
$params = [];
$types  = "";

if ($id_cabang) {
    $where[]  = "p.Id_cabang = ?";
    $params[] = $id_cabang;
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

if ($filter_stok === 'tersedia') {
    $where[] = "p.stok >= 10";
} elseif ($filter_stok === 'menipis') {
    $where[] = "p.stok > 0 AND p.stok < 10";
} elseif ($filter_stok === 'habis') {
    $where[] = "p.stok = 0";
}

$where_clause = implode(' AND ', $where);

$sql  = "SELECT p.*, c.Nama_cabang 
         FROM produk p 
         LEFT JOIN Cabang c ON p.Id_cabang = c.id 
         WHERE $where_clause 
         ORDER BY p.nama_produk ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$produks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
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
                <h2 class="fw-bold mb-1">Data Stok Produk</h2>
                <p class="text-muted mb-0">Pantau ketersediaan dan tambah stok barang cabang <strong><?= htmlspecialchars($_SESSION['nama_cabang'] ?? '') ?></strong></p>
            </div>
        </div>

        <?php if ($pesan): ?>
            <div class="alert alert-<?= $pesan_type ?> alert-dismissible fade show" role="alert">
                <?= $pesan ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- TABEL PRODUK -->
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
                                        placeholder="Cari kode barcode, nama, merk...">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="filter_kategori" onchange="this.form.submit()">
                                    <option value="">Semua Kategori</option>
                                    <option value="Aksesoris HP" <?= $filter_kategori === 'Aksesoris HP' ? 'selected' : '' ?>>Aksesoris HP</option>
                                    <option value="Voucher Internet" <?= $filter_kategori === 'Voucher Internet' ? 'selected' : '' ?>>Voucher Internet</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="filter_stok" onchange="this.form.submit()">
                                    <option value="">Semua Status Stok</option>
                                    <option value="tersedia" <?= $filter_stok === 'tersedia' ? 'selected' : '' ?>>Tersedia (&ge;10)</option>
                                    <option value="menipis"  <?= $filter_stok === 'menipis'  ? 'selected' : '' ?>>Menipis (&lt;10)</option>
                                    <option value="habis"    <?= $filter_stok === 'habis'    ? 'selected' : '' ?>>Habis (0)</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-1">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i></button>
                                <a href="data-produk.php" class="btn btn-secondary w-100"><i class="fas fa-undo"></i></a>
                            </div>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
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
                                            Tidak ada data produk ditemukan.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($produks as $i => $p): ?>
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
                                            <td class="fw-bold text-primary"><?= formatRupiah($p['harga_jual']) ?></td>
                                            <td>
                                                <?php if ($p['stok'] == 0): ?>
                                                    <span class="badge bg-danger">0 <?= $p['satuan'] ?></span>
                                                <?php elseif ($p['stok'] < 10): ?>
                                                    <span class="badge bg-warning text-dark"><?= $p['stok'] ?> <?= $p['satuan'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><?= $p['stok'] ?> <?= $p['satuan'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= $p['status'] === 'Aktif' ? 'bg-success' : 'bg-danger' ?>">
                                                    <?= htmlspecialchars($p['status']) ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <!-- Tombol Detail -->
                                                    <button type="button" class="btn btn-info text-white btnDetailProduk"
                                                        data-kode="<?= htmlspecialchars($p['kode_produk']) ?>"
                                                        data-nama="<?= htmlspecialchars($p['nama_produk']) ?>"
                                                        data-merk="<?= htmlspecialchars($p['merk'] ?: '-') ?>"
                                                        data-kategori="<?= htmlspecialchars($p['kategori']) ?>"
                                                        data-harga="<?= formatRupiah($p['harga_jual']) ?>"
                                                        data-stok="<?= $p['stok'] ?> <?= $p['satuan'] ?>"
                                                        data-status="<?= $p['status'] ?>"
                                                        data-deskripsi="<?= htmlspecialchars($p['deskripsi'] ?: '-') ?>"
                                                        title="Detail">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <!-- Tombol Tambah Stok -->
                                                    <button type="button" class="btn btn-primary btnUpdateStok"
                                                        data-id="<?= $p['id'] ?>"
                                                        data-nama="<?= htmlspecialchars($p['nama_produk']) ?>"
                                                        data-stok="<?= $p['stok'] ?>"
                                                        data-satuan="<?= htmlspecialchars($p['satuan']) ?>"
                                                        title="Restock">
                                                        <i class="fas fa-boxes me-1"></i>Stok
                                                    </button>
                                                </div>
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

    <!-- ============================================================ -->
    <!-- MODAL UPDATE STOK DINAMIS (DITARUH DI LUAR TABEL)            -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalRestock" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-boxes me-2"></i>Tambah Stok Produk</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="data-produk.php">
                    <input type="hidden" name="action" value="tambah_stok">
                    <input type="hidden" name="produk_id" id="restockProdukId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label text-muted small mb-1">Nama Produk</label>
                            <h5 class="fw-bold mb-0 text-primary" id="restockNamaProduk">-</h5>
                        </div>
                        <div class="p-2 bg-light rounded border mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Sisa Stok Sekarang:</span>
                                <strong id="restockStokSaatIni">0 Unit</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Jumlah Masuk (Restock) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="jumlah_masuk" min="1" placeholder="Masukkan jumlah barang" required autofocus>
                                <span class="input-group-text" id="restockSatuan">Pcs</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Simpan Stok Masuk</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL DETAIL PRODUK DINAMIS (DITARUH DI LUAR TABEL)          -->
    <!-- ============================================================ -->
    <div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Detail Produk</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-2"><div class="col-4 fw-bold">Kode Barcode:</div><div class="col-8"><code id="detKode">-</code></div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Nama Produk:</div><div class="col-8" id="detNama">-</div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Merk:</div><div class="col-8" id="detMerk">-</div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Kategori:</div><div class="col-8"><span class="badge bg-secondary" id="detKategori">-</span></div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Harga Jual:</div><div class="col-8 text-primary fw-bold" id="detHarga">-</div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Sisa Stok:</div><div class="col-8"><strong id="detStok">-</strong></div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Status:</div><div class="col-8" id="detStatus">-</div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Deskripsi:</div><div class="col-8" id="detDeskripsi">-</div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../asset/script.js"></script>
    <script>
        const modalRestockEl = new bootstrap.Modal(document.getElementById('modalRestock'));
        const modalDetailEl  = new bootstrap.Modal(document.getElementById('modalDetail'));

        // Handle Klik Tombol Update Stok
        document.querySelectorAll('.btnUpdateStok').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('restockProdukId').value = this.dataset.id;
                document.getElementById('restockNamaProduk').textContent = this.dataset.nama;
                document.getElementById('restockStokSaatIni').textContent = this.dataset.stok + ' ' + this.dataset.satuan;
                document.getElementById('restockSatuan').textContent = this.dataset.satuan;
                modalRestockEl.show();
            });
        });

        // Handle Klik Tombol Detail
        document.querySelectorAll('.btnDetailProduk').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('detKode').textContent = this.dataset.kode;
                document.getElementById('detNama').textContent = this.dataset.nama;
                document.getElementById('detMerk').textContent = this.dataset.merk;
                document.getElementById('detKategori').textContent = this.dataset.kategori;
                document.getElementById('detHarga').textContent = this.dataset.harga;
                document.getElementById('detStok').textContent = this.dataset.stok;
                document.getElementById('detStatus').innerHTML = `<span class="badge ${this.dataset.status === 'Aktif' ? 'bg-success' : 'bg-danger'}">${this.dataset.status}</span>`;
                document.getElementById('detDeskripsi').textContent = this.dataset.deskripsi;
                modalDetailEl.show();
            });
        });
    </script>
</body>

</html>