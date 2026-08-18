<?php
require_once '../config.php';
checkRole(['pemilik']);

$conn = getConnection();
$pageTitle = "Kelola Master Cabang";
$pesan = '';
$pesan_type = '';

// ============================================================
// 1. TAMBAH CABANG BARU
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'tambah') {
    $nama_cabang = trim($_POST['nama_cabang'] ?? '');
    $lokasi      = trim($_POST['lokasi'] ?? '');

    if (!$nama_cabang || !$lokasi) {
        $pesan = 'Nama cabang dan lokasi / alamat wajib diisi!';
        $pesan_type = 'danger';
    } else {
        $stmt = $conn->prepare("INSERT INTO Cabang (Nama_cabang, Lokasi) VALUES (?, ?)");
        $stmt->bind_param("ss", $nama_cabang, $lokasi);
        if ($stmt->execute()) {
            $pesan = "Cabang <strong>" . htmlspecialchars($nama_cabang) . "</strong> berhasil ditambahkan!";
            $pesan_type = 'success';
        } else {
            $pesan = 'Gagal menambahkan cabang: ' . $conn->error;
            $pesan_type = 'danger';
        }
        $stmt->close();
    }
}

// ============================================================
// 2. EDIT DATA CABANG
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id          = intval($_POST['id'] ?? 0);
    $nama_cabang = trim($_POST['nama_cabang'] ?? '');
    $lokasi      = trim($_POST['lokasi'] ?? '');

    if ($id <= 0 || !$nama_cabang || !$lokasi) {
        $pesan = 'Semua field wajib diisi!';
        $pesan_type = 'danger';
    } else {
        $stmt = $conn->prepare("UPDATE Cabang SET Nama_cabang = ?, Lokasi = ? WHERE id = ?");
        $stmt->bind_param("ssi", $nama_cabang, $lokasi, $id);
        if ($stmt->execute()) {
            $pesan = "Informasi cabang berhasil diperbarui!";
            $pesan_type = 'success';
        } else {
            $pesan = 'Gagal memperbarui cabang: ' . $conn->error;
            $pesan_type = 'danger';
        }
        $stmt->close();
    }
}

// ============================================================
// 3. HAPUS CABANG
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'hapus') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        // Cek apakah masih ada transaksi atau kasir di cabang ini
        $cek = $conn->prepare("SELECT COUNT(*) AS total FROM transaksi WHERE Id_cabang = ?");
        $cek->bind_param("i", $id);
        $cek->execute();
        $trx_count = $cek->get_result()->fetch_assoc()['total'];
        $cek->close();

        if ($trx_count > 0) {
            $pesan = 'Cabang tidak dapat dihapus karena sudah memiliki riwayat transaksi penjualan!';
            $pesan_type = 'danger';
        } else {
            $stmt = $conn->prepare("DELETE FROM Cabang WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $pesan = 'Cabang berhasil dihapus!';
                $pesan_type = 'success';
            } else {
                $pesan = 'Gagal menghapus cabang: ' . $conn->error;
                $pesan_type = 'danger';
            }
            $stmt->close();
        }
    }
}

// ============================================================
// 4. AMBIL DATA CABANG BESERTA STATISTIKNYA
// ============================================================
$search = trim($_GET['search'] ?? '');
$where = "";
$params = [];
$types = "";

if ($search !== '') {
    $where = "WHERE c.Nama_cabang LIKE ? OR c.Lokasi LIKE ?";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types = "ss";
}

$sql = "SELECT c.*,
        (SELECT COUNT(*) FROM users u WHERE u.id_cabang = c.id AND u.role = 'kasir') AS total_kasir,
        (SELECT COUNT(*) FROM produk p WHERE p.Id_cabang = c.id) AS total_produk,
        (SELECT COUNT(*) FROM transaksi t WHERE t.Id_cabang = c.id) AS total_transaksi,
        (SELECT COALESCE(SUM(t.total_harga), 0) FROM transaksi t WHERE t.Id_cabang = c.id) AS total_omzet
        FROM Cabang c
        $where
        ORDER BY c.Nama_cabang ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$cabangs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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

<body class="mobile-card-tables">

    <?php include '../layout/sidebar.php'; ?>
    <?php include '../layout/navbar.php'; ?>
    <div class="overlay" id="overlay"></div>

    <main class="main-content">
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="fw-bold mb-1">Kelola Master Cabang</h2>
                <p class="text-muted mb-0">Atur seluruh outlet cabang konter Fedly Cell</p>
            </div>
            <div>
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambahCabang">
                    <i class="fas fa-store me-2"></i>Tambah Cabang Baru
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
                    <div class="row mb-3 g-2">
                        <div class="col-md-6">
                            <form method="GET" action="">
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" name="search"
                                        value="<?= htmlspecialchars($search) ?>"
                                        placeholder="Cari nama cabang atau alamat/lokasi...">
                                    <button class="btn btn-primary" type="submit">Cari</button>
                                    <?php if ($search): ?>
                                        <a href="cabang.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>Nama Cabang</th>
                                    <th>Alamat / Lokasi</th>
                                    <th class="text-center">Kasir Bertugas</th>
                                    <th class="text-center">Total Produk</th>
                                    <th>Total Omzet</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($cabangs)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="fas fa-store-slash fa-2x mb-2 d-block"></i>
                                            Belum ada data cabang toko.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($cabangs as $i => $cb): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td>
                                                <strong class="text-primary fs-6"><?= htmlspecialchars($cb['Nama_cabang']) ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($cb['Lokasi']) ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-info text-dark rounded-pill"><?= $cb['total_kasir'] ?> Kasir</span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary rounded-pill"><?= $cb['total_produk'] ?> Produk</span>
                                            </td>
                                            <td class="fw-bold"><?= formatRupiah($cb['total_omzet']) ?></td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalEditCabang<?= $cb['id'] ?>" title="Edit Cabang">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalHapusCabang<?= $cb['id'] ?>" title="Hapus Cabang">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <div class="modal fade" id="modalEditCabang<?= $cb['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-warning text-dark">
                                                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Cabang</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="cabang.php">
                                                        <input type="hidden" name="action" value="edit">
                                                        <input type="hidden" name="id" value="<?= $cb['id'] ?>">
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Nama Cabang <span class="text-danger">*</span></label>
                                                                <input type="text" class="form-control" name="nama_cabang" value="<?= htmlspecialchars($cb['Nama_cabang']) ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">Alamat / Lokasi <span class="text-danger">*</span></label>
                                                                <textarea class="form-control" name="lokasi" rows="3" required><?= htmlspecialchars($cb['Lokasi']) ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-warning btn-sm">Simpan Perubahan</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal fade" id="modalHapusCabang<?= $cb['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-sm modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-danger text-white">
                                                        <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Hapus Cabang</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        Yakin ingin menghapus <strong><?= htmlspecialchars($cb['Nama_cabang']) ?></strong>?
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                        <form method="POST" action="cabang.php">
                                                            <input type="hidden" name="action" value="hapus">
                                                            <input type="hidden" name="id" value="<?= $cb['id'] ?>">
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
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="modalTambahCabang" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Tambah Cabang Baru</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="cabang.php">
                    <input type="hidden" name="action" value="tambah">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nama Cabang <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="nama_cabang" placeholder="Contoh: Cabang Simpang Lima, Outlet 2" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Alamat / Lokasi Lengkap <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="lokasi" rows="3" placeholder="Contoh: Jl. Ahmad Yani No. 45, Kota Semarang" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Simpan Cabang</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../asset/script.js"></script>
</body>

</html>