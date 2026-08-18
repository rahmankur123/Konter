<?php
require_once '../config.php';
checkRole(['pemilik']);

$conn = getConnection();
$pageTitle = "Manajemen User & Kasir";
$pesan = '';
$pesan_type = '';

// Ambil daftar seluruh cabang untuk dropdown
$daftar_cabang = $conn->query("SELECT id, Nama_cabang FROM Cabang ORDER BY Nama_cabang ASC")->fetch_all(MYSQLI_ASSOC);

// ============================================================
// 1. PROSES TAMBAH USER
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'tambah') {
    $username   = trim($_POST['username'] ?? '');
    $nama       = trim($_POST['nama_lengkap'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi_password'] ?? '';
    $role       = $_POST['role'] ?? '';
    $id_cabang  = !empty($_POST['id_cabang']) ? intval($_POST['id_cabang']) : null;

    // Jika role = pemilik, id_cabang harus NULL
    if ($role === 'pemilik') {
        $id_cabang = null;
    }

    if (!$username || !$nama || !$email || !$password || !$role) {
        $pesan = 'Semua field wajib diisi!';
        $pesan_type = 'danger';
    } elseif ($role === 'kasir' && empty($id_cabang)) {
        $pesan = 'Untuk role Kasir, wajib memilih cabang penugasan!';
        $pesan_type = 'danger';
    } elseif ($password !== $konfirmasi) {
        $pesan = 'Password dan konfirmasi password tidak cocok!';
        $pesan_type = 'danger';
    } elseif (!in_array($role, ['kasir', 'pemilik'])) {
        $pesan = 'Role pengguna tidak valid!';
        $pesan_type = 'danger';
    } else {
        $cek = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $cek->bind_param("ss", $username, $email);
        $cek->execute();
        $cek->store_result();
        if ($cek->num_rows > 0) {
            $pesan = 'Username atau email sudah digunakan!';
            $pesan_type = 'danger';
        } else {
            // Hash password untuk keamanan
            $hashed_pass = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, email, password, role, id_cabang) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssi", $username, $nama, $email, $hashed_pass, $role, $id_cabang);
            if ($stmt->execute()) {
                $pesan = 'User baru berhasil ditambahkan!';
                $pesan_type = 'success';
            } else {
                $pesan = 'Gagal menyimpan user: ' . $conn->error;
                $pesan_type = 'danger';
            }
            $stmt->close();
        }
        $cek->close();
    }
}

// ============================================================
// 2. PROSES EDIT USER
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id         = intval($_POST['id'] ?? 0);
    $username   = trim($_POST['username'] ?? '');
    $nama       = trim($_POST['nama_lengkap'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $role       = $_POST['role'] ?? '';
    $id_cabang  = !empty($_POST['id_cabang']) ? intval($_POST['id_cabang']) : null;
    $ubah_pass  = isset($_POST['ubah_password']) ? true : false;
    $password   = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi_password'] ?? '';

    if ($role === 'pemilik') {
        $id_cabang = null;
    }

    if (!$id || !$username || !$nama || !$email || !$role) {
        $pesan = 'Semua field wajib diisi!';
        $pesan_type = 'danger';
    } elseif ($role === 'kasir' && empty($id_cabang)) {
        $pesan = 'Untuk role Kasir, wajib memilih cabang penugasan!';
        $pesan_type = 'danger';
    } elseif (!in_array($role, ['kasir', 'pemilik'])) {
        $pesan = 'Role pengguna tidak valid!';
        $pesan_type = 'danger';
    } elseif ($ubah_pass && ($password !== $konfirmasi || empty($password))) {
        $pesan = 'Password baru dan konfirmasi tidak cocok atau kosong!';
        $pesan_type = 'danger';
    } else {
        $cek = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $cek->bind_param("ssi", $username, $email, $id);
        $cek->execute();
        $cek->store_result();
        if ($cek->num_rows > 0) {
            $pesan = 'Username atau email sudah digunakan akun lain!';
            $pesan_type = 'danger';
        } else {
            if ($ubah_pass) {
                $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET username=?, nama_lengkap=?, email=?, role=?, id_cabang=?, password=? WHERE id=?");
                $stmt->bind_param("ssssisi", $username, $nama, $email, $role, $id_cabang, $hashed_pass, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET username=?, nama_lengkap=?, email=?, role=?, id_cabang=? WHERE id=?");
                $stmt->bind_param("ssssii", $username, $nama, $email, $role, $id_cabang, $id);
            }

            if ($stmt->execute()) {
                $pesan = 'Data user berhasil diperbarui!';
                $pesan_type = 'success';
            } else {
                $pesan = 'Gagal memperbarui user: ' . $conn->error;
                $pesan_type = 'danger';
            }
            $stmt->close();
        }
        $cek->close();
    }
}

// ============================================================
// 3. PROSES HAPUS USER
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'hapus') {
    $id = intval($_POST['id'] ?? 0);
    if ($id === intval($_SESSION['user_id'])) {
        $pesan = 'Tidak dapat menghapus akun Anda yang sedang aktif!';
        $pesan_type = 'danger';
    } elseif ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $pesan = 'User berhasil dihapus!';
            $pesan_type = 'success';
        } else {
            $pesan = 'Gagal menghapus user karena terikat data transaksi kasir!';
            $pesan_type = 'danger';
        }
        $stmt->close();
    }
}

// ============================================================
// 4. QUERY TABEL & FILTER
// ============================================================
$search        = trim($_GET['search'] ?? '');
$filter_role   = $_GET['filter_role'] ?? '';
$filter_cabang = $_GET['filter_cabang'] ?? '';

$where  = ["1=1"];
$params = [];
$types  = "";

if ($search !== '') {
    $like     = "%$search%";
    $where[]  = "(u.username LIKE ? OR u.nama_lengkap LIKE ? OR u.email LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}
if ($filter_role !== '') {
    $where[]  = "u.role = ?";
    $params[] = $filter_role;
    $types   .= "s";
}
if ($filter_cabang !== '') {
    $where[]  = "u.id_cabang = ?";
    $params[] = intval($filter_cabang);
    $types   .= "i";
}

$where_clause = implode(' AND ', $where);

$sql  = "SELECT u.*, c.Nama_cabang 
         FROM users u 
         LEFT JOIN Cabang c ON u.id_cabang = c.id 
         WHERE $where_clause 
         ORDER BY u.role ASC, u.nama_lengkap ASC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ============================================================
// 5. AMBIL DATA MODAL (DETAIL & EDIT)
// ============================================================
$detail_user = null;
if (!empty($_GET['detail_id']) && (int)$_GET['detail_id'] > 0) {
    $did   = intval($_GET['detail_id']);
    $dstmt = $conn->prepare("SELECT u.*, c.Nama_cabang FROM users u LEFT JOIN Cabang c ON u.id_cabang = c.id WHERE u.id = ?");
    $dstmt->bind_param("i", $did);
    $dstmt->execute();
    $detail_user = $dstmt->get_result()->fetch_assoc();
    $dstmt->close();
}

$edit_user = null;
if (!empty($_GET['edit_id']) && (int)$_GET['edit_id'] > 0) {
    $eid   = intval($_GET['edit_id']);
    $estmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $estmt->bind_param("i", $eid);
    $estmt->execute();
    $edit_user = $estmt->get_result()->fetch_assoc();
    $estmt->close();
}

function qsUser($search, $filter_role, $filter_cabang) {
    return http_build_query(array_filter([
        'search'        => $search,
        'filter_role'   => $filter_role,
        'filter_cabang' => $filter_cabang,
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
<body class="mobile-card-tables">

    <?php include '../layout/sidebar.php'; ?>[cite: 4]
    <?php include '../layout/navbar.php'; ?>[cite: 4]
    <div class="overlay" id="overlay"></div>

    <main class="main-content">
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="fw-bold mb-1">Manajemen User & Penugasan Kasir</h2>
                <p class="text-muted mb-0">Kelola akun pemilik toko dan penugasan kasir di setiap cabang</p>
            </div>
            <div>
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambahUser">
                    <i class="fas fa-user-plus me-2"></i>Tambah User Baru
                </button>
            </div>
        </div>

        <!-- Alert Notifikasi -->
        <?php if ($pesan): ?>
        <div class="alert alert-<?= $pesan_type ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- TABEL DATA USER -->
        <div class="row">
            <div class="col-12">
                <div class="table-container">
                    <form method="GET" action="">
                        <div class="row mb-3 g-2">
                            <div class="col-md-5">
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" name="search"
                                           value="<?= htmlspecialchars($search) ?>"
                                           placeholder="Cari username, nama, atau email...">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" name="filter_role" onchange="this.form.submit()">
                                    <option value="">Semua Role</option>
                                    <option value="pemilik" <?= $filter_role === 'pemilik' ? 'selected' : '' ?>>Pemilik</option>
                                    <option value="kasir"   <?= $filter_role === 'kasir'   ? 'selected' : '' ?>>Kasir</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="filter_cabang" onchange="this.form.submit()">
                                    <option value="">Semua Cabang</option>
                                    <?php foreach ($daftar_cabang as $cb): ?>
                                        <option value="<?= $cb['id'] ?>" <?= $filter_cabang == $cb['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cb['Nama_cabang']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-1">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i></button>
                                <a href="manajemen-user.php" class="btn btn-secondary w-100"><i class="fas fa-undo"></i></a>
                            </div>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="userTable">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>Username</th>
                                    <th>Nama Lengkap</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Cabang Penugasan</th>
                                    <th>Dibuat</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="fas fa-users-slash fa-2x mb-2 d-block"></i>
                                        Tidak ada data pengguna ditemukan.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($users as $i => $user): ?>
                                <?php $q = qsUser($search, $filter_role, $filter_cabang); ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                    <td><?= htmlspecialchars($user['nama_lengkap']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td>
                                        <?php if ($user['role'] === 'pemilik'): ?>
                                            <span class="badge bg-success">Pemilik</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">Kasir</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['role'] === 'pemilik'): ?>
                                            <span class="badge bg-light text-dark border">Semua Cabang (Owner)</span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark">
                                                <i class="fas fa-store me-1"></i><?= htmlspecialchars($user['Nama_cabang'] ?? 'Belum Diatur') ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="manajemen-user.php?detail_id=<?= $user['id'] ?>&<?= $q ?>" class="btn btn-info text-white" title="Detail"><i class="fas fa-eye"></i></a>
                                            <a href="manajemen-user.php?edit_id=<?= $user['id'] ?>&<?= $q ?>" class="btn btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalHapus<?= $user['id'] ?>" title="Hapus"><i class="fas fa-trash"></i></button>
                                            <?php else: ?>
                                            <button class="btn btn-secondary" disabled title="Akun Aktif"><i class="fas fa-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Modal Hapus -->
                                <div class="modal fade" id="modalHapus<?= $user['id'] ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-sm modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Hapus Akun</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                Yakin ingin menghapus user <strong><?= htmlspecialchars($user['username']) ?></strong>?
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                <form method="POST" action="manajemen-user.php">
                                                    <input type="hidden" name="action" value="hapus">
                                                    <input type="hidden" name="id" value="<?= $user['id'] ?>">
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

    <!-- ========== MODAL TAMBAH USER ========== -->
    <div class="modal fade" id="modalTambahUser" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Tambah User Baru</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="manajemen-user.php">
                    <input type="hidden" name="action" value="tambah">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" required autocomplete="off">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_lengkap" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" name="role" id="selectRoleTambah" required onchange="toggleCabangField(this.value, 'wrapCabangTambah')">
                                    <option value="">Pilih Role</option>
                                    <option value="kasir">Kasir</option>
                                    <option value="pemilik">Pemilik</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3" id="wrapCabangTambah">
                                <label class="form-label">Tugaskan di Cabang <span class="text-danger">*</span></label>
                                <select class="form-select" name="id_cabang" id="selectCabangTambah">
                                    <option value="">-- Pilih Cabang --</option>
                                    <?php foreach ($daftar_cabang as $cb): ?>
                                        <option value="<?= $cb['id'] ?>"><?= htmlspecialchars($cb['Nama_cabang']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="password" required autocomplete="new-password">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Konfirmasi Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="konfirmasi_password" required autocomplete="new-password">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Simpan User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== MODAL EDIT USER ========== -->
    <div class="modal fade" id="modalEditUser" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Data User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="manajemen-user.php">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?= $edit_user['id'] ?? '' ?>">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($edit_user['username'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_lengkap" value="<?= htmlspecialchars($edit_user['nama_lengkap'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($edit_user['email'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" name="role" id="selectRoleEdit" required onchange="toggleCabangField(this.value, 'wrapCabangEdit')">
                                    <option value="kasir"   <?= ($edit_user['role'] ?? '') === 'kasir'   ? 'selected' : '' ?>>Kasir</option>
                                    <option value="pemilik" <?= ($edit_user['role'] ?? '') === 'pemilik' ? 'selected' : '' ?>>Pemilik</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3" id="wrapCabangEdit" style="<?= ($edit_user['role'] ?? '') === 'pemilik' ? 'display:none;' : '' ?>">
                                <label class="form-label">Cabang Penugasan <span class="text-danger">*</span></label>
                                <select class="form-select" name="id_cabang" id="selectCabangEdit">
                                    <option value="">-- Pilih Cabang --</option>
                                    <?php foreach ($daftar_cabang as $cb): ?>
                                        <option value="<?= $cb['id'] ?>" <?= ($edit_user['id_cabang'] ?? '') == $cb['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cb['Nama_cabang']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <hr>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="ubah_password" id="ubahPasswordCheck"
                                   onchange="document.getElementById('passwordFields').style.display = this.checked ? 'block' : 'none'">
                            <label class="form-check-label fw-bold" for="ubahPasswordCheck">Centang jika ingin ubah password</label>
                        </div>
                        <div id="passwordFields" style="display:none;">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Password Baru</label>
                                    <input type="password" class="form-control" name="password" autocomplete="new-password">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Konfirmasi Password Baru</label>
                                    <input type="password" class="form-control" name="konfirmasi_password" autocomplete="new-password">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="manajemen-user.php?<?= qsUser($search, $filter_role, $filter_cabang) ?>" class="btn btn-secondary">Batal</a>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i>Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========== MODAL DETAIL USER ========== -->
    <div class="modal fade" id="modalDetailUser" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Detail Informasi User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($detail_user): ?>
                    <div class="row mb-2"><div class="col-4 fw-bold">Username:</div><div class="col-8"><strong><?= htmlspecialchars($detail_user['username']) ?></strong></div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Nama Lengkap:</div><div class="col-8"><?= htmlspecialchars($detail_user['nama_lengkap']) ?></div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Email:</div><div class="col-8"><?= htmlspecialchars($detail_user['email']) ?></div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Role:</div><div class="col-8"><span class="badge <?= $detail_user['role'] === 'pemilik' ? 'bg-success' : 'bg-primary' ?>"><?= ucfirst($detail_user['role']) ?></span></div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Cabang:</div><div class="col-8"><span class="badge bg-light text-dark border"><?= htmlspecialchars($detail_user['Nama_cabang'] ?? 'Semua Cabang (Owner)') ?></span></div></div>
                    <div class="row mb-2"><div class="col-4 fw-bold">Terdaftar Pada:</div><div class="col-8"><?= date('d/m/Y H:i', strtotime($detail_user['created_at'])) ?></div></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <a href="manajemen-user.php?<?= qsUser($search, $filter_role, $filter_cabang) ?>" class="btn btn-secondary">Tutup</a>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../asset/script.js"></script>
    <script>
        function toggleCabangField(roleValue, targetWrapId) {
            const wrap = document.getElementById(targetWrapId);
            if (roleValue === 'pemilik') {
                wrap.style.display = 'none';
            } else {
                wrap.style.display = 'block';
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            <?php if ($detail_user): ?>
            new bootstrap.Modal(document.getElementById('modalDetailUser')).show();
            <?php endif; ?>

            <?php if ($edit_user): ?>
            new bootstrap.Modal(document.getElementById('modalEditUser')).show();
            <?php endif; ?>
        });
    </script>
</body>
</html>