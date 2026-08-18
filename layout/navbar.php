<?php
require_once '../config.php';

$nama = $_SESSION['nama_lengkap'] ?? 'User';
$role = $_SESSION['role'] ?? 'kasir';
$pageTitleNavbar = $pageTitle ?? 'Dashboard';

// Handle filter cabang untuk Pemilik dari Navbar (jika ada request switch)
if ($role === 'pemilik') {
    if (isset($_GET['switch_cabang'])) {
        $sw = trim($_GET['switch_cabang']);
        $_SESSION['filter_cabang_id'] = ($sw !== '' && intval($sw) > 0) ? intval($sw) : null;
        
        // Hapus query switch_cabang dan reload halaman
        $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
        header("Location: $cleanUrl");
        exit;
    }

    $conn = getConnection();
    $daftarCabangNavbar = $conn->query("SELECT id, Nama_cabang FROM Cabang ORDER BY Nama_cabang ASC")->fetch_all(MYSQLI_ASSOC);
}
?>

<nav class="navbar-custom d-flex justify-content-between align-items-center px-3 py-2">
    <div class="d-flex align-items-center gap-3">
        <button class="mobile-menu-btn btn btn-sm border-0" id="mobileMenuBtn">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="navbar-title fs-5 mb-0 fw-bold"><?= htmlspecialchars($pageTitleNavbar); ?></h1>
    </div>

    <div class="navbar-user d-flex align-items-center gap-3">
        
        <?php if ($role === 'pemilik' && !empty($daftarCabangNavbar)): ?>
            <div class="d-none d-md-flex align-items-center">
                <form method="GET" action="" class="m-0">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-end-0 text-primary">
                            <i class="fas fa-store"></i>
                        </span>
                        <select name="switch_cabang" class="form-select form-select-sm border-start-0 ps-0" onchange="this.form.submit()" style="min-width: 160px;">
                            <option value="">-- Semua Cabang --</option>
                            <?php foreach ($daftarCabangNavbar as $cb): ?>
                                <option value="<?= $cb['id'] ?>" <?= (isset($_SESSION['filter_cabang_id']) && $_SESSION['filter_cabang_id'] == $cb['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cb['Nama_cabang']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        <?php elseif ($role === 'kasir'): ?>
            <div class="d-none d-sm-block">
                <span class="badge bg-light text-primary border px-2 py-1">
                    <i class="fas fa-store me-1"></i><?= htmlspecialchars($_SESSION['nama_cabang'] ?? 'Cabang') ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="user-info text-end d-none d-sm-block">
            <div class="user-name fw-semibold small"><?= htmlspecialchars($nama); ?></div>
            <div class="user-role text-muted" style="font-size: 11px;"><?= ucfirst($role); ?></div>
        </div>

        <div class="user-avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 35px; height: 35px;">
            <i class="fas fa-user small"></i>
        </div>

        <a href="../logout.php?role=<?= urlencode($role); ?>" class="btn btn-outline-danger btn-sm rounded-pill px-3" style="text-decoration: none;">
            <i class="fas fa-sign-out-alt me-1"></i> <span class="d-none d-sm-inline">Logout</span>
        </a>
    </div>
</nav>