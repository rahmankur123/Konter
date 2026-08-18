<?php
require_once '../config.php';

if (!isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

$nama = $_SESSION['nama_lengkap'] ?? 'User';
$role = $_SESSION['role'];

// Helper mendeteksi menu aktif berdasarkan URL
$current_page = basename($_SERVER['PHP_SELF']);
function isActive($pageName, $currentPage) {
    return ($pageName === $currentPage) ? 'active' : '';
}
?>

<!-- ========== SIDEBAR ========== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <i class="fas fa-mobile-alt fa-2x"></i>
        <h4>Fedly Cell POS</h4>
        <p class="badge <?= $role === 'pemilik' ? 'bg-warning text-dark' : 'bg-primary' ?> px-2 py-1">
            <?= ucfirst($role); ?>
        </p>
    </div>

    <ul class="sidebar-menu">

        <?php if ($role === 'kasir'): ?>

            <li class="<?= isActive('dashboard.php', $current_page) ?>">
                <a href="../kasir/dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="<?= isActive('transaksi.php', $current_page) ?>">
                <a href="../kasir/transaksi.php">
                    <i class="fas fa-cash-register"></i>
                    <span>Kasir POS</span>
                </a>
            </li>

            <li class="<?= isActive('data-produk.php', $current_page) ?>">
                <a href="../kasir/data-produk.php">
                    <i class="fas fa-boxes"></i>
                    <span>Stok & Produk</span>
                </a>
            </li>

            <li class="<?= isActive('laporan.php', $current_page) ?>">
                <a href="../kasir/laporan.php">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Laporan Kasir</span>
                </a>
            </li>

        <?php elseif ($role === 'pemilik'): ?>

            <li class="<?= isActive('dashboard.php', $current_page) ?>">
                <a href="../pemilik/dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard Utama</span>
                </a>
            </li>

            <li class="<?= isActive('data-produk.php', $current_page) ?>">
                <a href="../pemilik/data-produk.php">
                    <i class="fas fa-box-open"></i>
                    <span>Master Produk</span>
                </a>
            </li>

            <li class="<?= isActive('rekening.php', $current_page) ?>">
                <a href="../pemilik/rekening.php">
                    <i class="fas fa-wallet"></i>
                    <span>Kelola Rekening & Saldo</span>
                </a>
            </li>

            <li class="<?= isActive('manajemen-user.php', $current_page) ?>">
                <a href="../pemilik/manajemen-user.php">
                    <i class="fas fa-users-cog"></i>
                    <span>Manajemen User / Kasir</span>
                </a>
            </li>

            <li class="<?= isActive('laporan.php', $current_page) ?>">
                <a href="../pemilik/laporan.php">
                    <i class="fas fa-chart-line"></i>
                    <span>Laporan & Analitik</span>
                </a>
            </li>

            <li class="<?= isActive('cabang.php', $current_page) ?>">
                <a href="../pemilik/cabang.php">
                    <i class="fas fa-store"></i>
                    <span>Master Cabang</span>
                </a>
            </li>

        <?php endif; ?>

    </ul>
</aside>

<!-- Overlay -->
<div class="overlay" id="overlay"></div>