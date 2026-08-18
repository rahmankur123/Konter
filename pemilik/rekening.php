<?php
require_once '../config.php';
checkRole(['pemilik']);

$conn = getConnection();
$pageTitle = "Kelola Rekening & Saldo";
$pesan = '';
$pesan_type = '';

// ============================================================
// 1. TAMBAH REKENING BARU
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'tambah_rekening') {
    $nama_rek   = trim($_POST['nama_rekening'] ?? '');
    $no_rek     = trim($_POST['no_rek'] ?? '');
    $tipe       = $_POST['tipe'] ?? 'Bank';
    $saldo_awal = floatval($_POST['saldo_awal'] ?? 0);

    if (!$nama_rek || !$no_rek) {
        $pesan = 'Nama rekening dan nomor rekening/HP wajib diisi!';
        $pesan_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            // Insert master rekening
            $stmt = $conn->prepare("INSERT INTO Rekening (Nama_Rekening, No_rek, Saldo, Tipe) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssds", $nama_rek, $no_rek, $saldo_awal, $tipe);
            $stmt->execute();
            $id_rekening = $conn->insert_id;
            $stmt->close();

            // Catat saldo awal jika > 0 ke Mutasi_rekening
            if ($saldo_awal > 0) {
                $insMutasi = $conn->prepare(
                    "INSERT INTO Mutasi_rekening (Id_saldo, id_cabang, id_transaksi, jenis_mutasi, Jumlah, saldo_awal, saldo_akhir, Keterangan) 
                     VALUES (?, NULL, NULL, 'Masuk', ?, 0, ?, 'Saldo Awal Pembuatan Akun')"
                );
                $insMutasi->bind_param("idd", $id_rekening, $saldo_awal, $saldo_awal);
                $insMutasi->execute();
                $insMutasi->close();
            }

            $conn->commit();
            $pesan = 'Akun rekening berhasil ditambahkan!';
            $pesan_type = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $pesan = 'Gagal menambahkan rekening: ' . $e->getMessage();
            $pesan_type = 'danger';
        }
    }
}

// ============================================================
// 2. MUTASI MANUAL (SUNTIK MODAL / TARIK SALDO)
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'mutasi_manual') {
    $id_rekening  = intval($_POST['id_rekening'] ?? 0);
    $jenis_mutasi = $_POST['jenis_mutasi'] ?? 'Masuk'; // 'Masuk' (Topup) atau 'Keluar' (Tarik)
    $jumlah       = floatval($_POST['jumlah'] ?? 0);
    $keterangan   = trim($_POST['keterangan'] ?? '');

    if ($id_rekening <= 0 || $jumlah <= 0) {
        $pesan = 'Pilih rekening dan masukkan nominal yang valid!';
        $pesan_type = 'danger';
    } else {
        $conn->begin_transaction();
        try {
            $ket = ($jenis_mutasi === 'Masuk' ? 'Suntik Modal: ' : 'Tarik Saldo: ') . ($keterangan ?: '-');
            catatMutasiRekening($conn, $id_rekening, null, null, $jenis_mutasi, $jumlah, $ket);
            $conn->commit();
            $pesan = "Mutasi saldo manual ($jenis_mutasi) sebesar " . formatRupiah($jumlah) . " berhasil dicatat!";
            $pesan_type = 'success';
        } catch (Exception $e) {
            $conn->rollback();
            $pesan = 'Gagal memproses mutasi: ' . $e->getMessage();
            $pesan_type = 'danger';
        }
    }
}

// ============================================================
// 3. EDIT REKENING
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'edit_rekening') {
    $id       = intval($_POST['id'] ?? 0);
    $nama_rek = trim($_POST['nama_rekening'] ?? '');
    $no_rek   = trim($_POST['no_rek'] ?? '');
    $tipe     = $_POST['tipe'] ?? 'Bank';

    if ($id <= 0 || !$nama_rek || !$no_rek) {
        $pesan = 'Data rekening tidak lengkap!';
        $pesan_type = 'danger';
    } else {
        $stmt = $conn->prepare("UPDATE Rekening SET Nama_Rekening=?, No_rek=?, Tipe=? WHERE id=?");
        $stmt->bind_param("sssi", $nama_rek, $no_rek, $tipe, $id);
        if ($stmt->execute()) {
            $pesan = 'Informasi akun rekening berhasil diperbarui!';
            $pesan_type = 'success';
        } else {
            $pesan = 'Gagal memperbarui rekening: ' . $conn->error;
            $pesan_type = 'danger';
        }
        $stmt->close();
    }
}

// ============================================================
// 4. HAPUS REKENING
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'hapus_rekening') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM Rekening WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $pesan = 'Akun rekening berhasil dihapus!';
            $pesan_type = 'success';
        } else {
            $pesan = 'Gagal menghapus rekening karena masih terikat riwayat transaksi!';
            $pesan_type = 'danger';
        }
        $stmt->close();
    }
}

// ============================================================
// 5. QUERY DATA MASTER & MUTASI
// ============================================================
// Ambil Daftar Rekening
$rekenings = $conn->query("SELECT * FROM Rekening ORDER BY Nama_Rekening ASC")->fetch_all(MYSQLI_ASSOC);

// Filter Riwayat Mutasi
$filter_rek  = intval($_GET['filter_rek'] ?? 0);
$filter_tgl  = $_GET['filter_tgl'] ?? date('Y-m-d');

$where_mutasi = ["DATE(m.created_at) = ?"];
$params_mut   = [$filter_tgl];
$types_mut    = "s";

if ($filter_rek > 0) {
    $where_mutasi[] = "m.Id_saldo = ?";
    $params_mut[]   = $filter_rek;
    $types_mut     .= "i";
}

$clause_mutasi = implode(' AND ', $where_mutasi);

$sql_mutasi = "SELECT m.*, r.Nama_Rekening, r.No_rek, c.Nama_cabang, t.no_transaksi 
               FROM Mutasi_rekening m
               JOIN Rekening r ON m.Id_saldo = r.id
               LEFT JOIN Cabang c ON m.id_cabang = c.id
               LEFT JOIN transaksi t ON m.id_transaksi = t.id
               WHERE $clause_mutasi
               ORDER BY m.created_at DESC";

$stmt_m = $conn->prepare($sql_mutasi);
$stmt_m->bind_param($types_mut, ...$params_mut);
$stmt_m->execute();
$riwayat_mutasi = $stmt_m->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_m->close();

// Total Saldo Keseluruhan
$total_saldo = array_sum(array_column($rekenings, 'Saldo'));
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

    <?php include '../layout/sidebar.php'; ?>[cite: 4]
    <?php include '../layout/navbar.php'; ?>[cite: 4]
    <div class="overlay" id="overlay"></div>

    <main class="main-content">
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="fw-bold mb-1">Kelola Rekening & Kas Saldo</h2>
                <p class="text-muted mb-0">Pantau perputaran modal saldo bank, e-wallet, dan server pulsa</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#modalMutasiManual">
                    <i class="fas fa-hand-holding-usd me-2"></i>Suntik / Tarik Saldo
                </button>
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambahRekening">
                    <i class="fas fa-plus-circle me-2"></i>Tambah Akun Rekening
                </button>
            </div>
        </div>

        <?php if ($pesan): ?>
            <div class="alert alert-<?= $pesan_type ?> alert-dismissible fade show" role="alert">
                <?= $pesan ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- KARTU DAFTAR AKUN REKENING -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="card bg-primary text-white p-3 h-100 shadow-sm border-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-white-50">Total Keseluruhan Saldo</small>
                            <h3 class="fw-bold mb-0 mt-1"><?= formatRupiah($total_saldo) ?></h3>
                        </div>
                        <i class="fas fa-wallet fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
            <?php foreach ($rekenings as $rek): ?>
                <div class="col-12 col-sm-6 col-md-4">
                    <div class="card p-3 h-100 shadow-sm border">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($rek['Nama_Rekening']) ?></h6>
                                <small class="text-muted"><?= htmlspecialchars($rek['No_rek']) ?></small>
                            </div>
                            <span class="badge bg-secondary"><?= htmlspecialchars($rek['Tipe']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <div>
                                <small class="text-muted d-block">Sisa Saldo:</small>
                                <span class="fs-5 fw-bold text-primary"><?= formatRupiah($rek['Saldo']) ?></span>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modalEditRek<?= $rek['id'] ?>"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalHapusRek<?= $rek['id'] ?>"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MODAL EDIT REKENING -->
                <div class="modal fade" id="modalEditRek<?= $rek['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Akun: <?= htmlspecialchars($rek['Nama_Rekening']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" action="rekening.php">
                                <input type="hidden" name="action" value="edit_rekening">
                                <input type="hidden" name="id" value="<?= $rek['id'] ?>">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Nama Akun / Provider <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="nama_rekening" value="<?= htmlspecialchars($rek['Nama_Rekening']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nomor Rekening / No HP <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="no_rek" value="<?= htmlspecialchars($rek['No_rek']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Tipe Akun</label>
                                        <select class="form-select" name="tipe">
                                            <option value="Bank" <?= $rek['Tipe'] === 'Bank' ? 'selected' : '' ?>>Bank</option>
                                            <option value="E-Wallet" <?= $rek['Tipe'] === 'E-Wallet' ? 'selected' : '' ?>>E-Wallet</option>
                                            <option value="Server Pulsa" <?= $rek['Tipe'] === 'Server Pulsa' ? 'selected' : '' ?>>Server Pulsa</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-warning btn-sm">Update Akun</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- MODAL HAPUS REKENING -->
                <div class="modal fade" id="modalHapusRek<?= $rek['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-sm modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Hapus Akun</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                Yakin ingin menghapus akun <strong><?= htmlspecialchars($rek['Nama_Rekening']) ?></strong>?
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                <form method="POST" action="rekening.php">
                                    <input type="hidden" name="action" value="hapus_rekening">
                                    <input type="hidden" name="id" value="<?= $rek['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- TABEL BUKU KAS / MUTASI REKENING -->
        <div class="row">
            <div class="col-12">
                <div class="table-container">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h5 class="mb-0"><i class="fas fa-history me-2 text-primary"></i>Riwayat Mutasi Saldo</h5>
                    </div>

                    <!-- Filter Mutasi -->
                    <form method="GET" action="" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <input type="date" class="form-control form-control-sm" name="filter_tgl" value="<?= htmlspecialchars($filter_tgl) ?>" onchange="this.form.submit()">
                        </div>
                        <div class="col-md-4">
                            <select class="form-select form-select-sm" name="filter_rek" onchange="this.form.submit()">
                                <option value="">-- Semua Akun Rekening --</option>
                                <?php foreach ($rekenings as $r): ?>
                                    <option value="<?= $r['id'] ?>" <?= $filter_rek == $r['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['Nama_Rekening']) ?> (<?= htmlspecialchars($r['No_rek']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Waktu</th>
                                    <th>Akun Rekening</th>
                                    <th>Cabang</th>
                                    <th>Jenis</th>
                                    <th>Nominal Mutasi</th>
                                    <th>Saldo Awal</th>
                                    <th>Saldo Akhir</th>
                                    <th>Keterangan / Transaksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($riwayat_mutasi)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">Belum ada catatan mutasi saldo pada tanggal ini.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($riwayat_mutasi as $m): ?>
                                        <tr>
                                            <td class="text-nowrap"><?= date('H:i:s', strtotime($m['created_at'])) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($m['Nama_Rekening']) ?></strong>
                                                <br><small class="text-muted"><?= htmlspecialchars($m['No_rek']) ?></small>
                                            </td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($m['Nama_cabang'] ?? 'Owner Pusat') ?></span></td>
                                            <td>
                                                <?php if ($m['jenis_mutasi'] === 'Masuk'): ?>
                                                    <span class="badge bg-success"><i class="fas fa-arrow-down me-1"></i>Masuk</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger"><i class="fas fa-arrow-up me-1"></i>Keluar</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold <?= $m['jenis_mutasi'] === 'Masuk' ? 'text-success' : 'text-danger' ?>">
                                                <?= ($m['jenis_mutasi'] === 'Masuk' ? '+' : '-') . ' ' . formatRupiah($m['Jumlah']) ?>
                                            </td>
                                            <td><?= formatRupiah($m['saldo_awal']) ?></td>
                                            <td><strong><?= formatRupiah($m['saldo_akhir']) ?></strong></td>
                                            <td>
                                                <?= htmlspecialchars($m['Keterangan']) ?>
                                                <?php if ($m['no_transaksi']): ?>
                                                    <br><small class="text-primary"><code><?= htmlspecialchars($m['no_transaksi']) ?></code></small>
                                                <?php endif; ?>
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

    <!-- ========== MODAL TAMBAH REKENING ========== -->
    <div class="modal fade" id="modalTambahRekening" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Tambah Akun Rekening</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="rekening.php">
                    <input type="hidden" name="action" value="tambah_rekening">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama Akun / Bank <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="nama_rekening" placeholder="Contoh: BCA Toko, DANA Outlet 1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nomor Rekening / No HP Akun <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="no_rek" placeholder="Contoh: 1234567890 / 08123456789" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tipe Akun</label>
                            <select class="form-select" name="tipe" required>
                                <option value="Bank">Bank</option>
                                <option value="E-Wallet">E-Wallet</option>
                                <option value="Server Pulsa">Server Pulsa</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Saldo Awal</label>
                            <input type="number" class="form-control" name="saldo_awal" min="0" value="0" placeholder="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Simpan Akun</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== MODAL MUTASI MANUAL (SUNTIK MODAL / TARIK TUNAI) ========== -->
    <div class="modal fade" id="modalMutasiManual" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Suntik / Tarik Saldo Modal</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="rekening.php">
                    <input type="hidden" name="action" value="mutasi_manual">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Pilih Akun Rekening <span class="text-danger">*</span></label>
                            <select class="form-select" name="id_rekening" required>
                                <option value="">-- Pilih Akun --</option>
                                <?php foreach ($rekenings as $r): ?>
                                    <option value="<?= $r['id'] ?>">
                                        <?= htmlspecialchars($r['Nama_Rekening']) ?> (Sisa: <?= formatRupiah($r['Saldo']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Aksi Perubahan <span class="text-danger">*</span></label>
                            <select class="form-select" name="jenis_mutasi" required>
                                <option value="Masuk">Suntik Modal (+) [Saldo Bertambah]</option>
                                <option value="Keluar">Tarik Saldo (-) [Saldo Berkurang]</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nominal Uang (Rp) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="jumlah" min="1" placeholder="Masukkan jumlah uang" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Keterangan Tambahan</label>
                            <textarea class="form-control" name="keterangan" rows="2" placeholder="Contoh: Deposit modal awal dari BCA pribadi"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check-circle me-1"></i>Proses Mutasi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../asset/script.js"></script>
</body>

</html>