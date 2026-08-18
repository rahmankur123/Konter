<?php
require_once '../config.php';
checkRole(['kasir', 'pemilik']);

$conn = getConnection();
ensureBuktiTransferColumn($conn);
$pageTitle = "Transaksi Kasir";
$pesan = '';
$pesan_type = '';

$id_cabang = getActiveCabangId();

if (!$id_cabang && $_SESSION['role'] === 'kasir') {
    die("Akses ditolak: Akun kasir belum terhubung ke cabang manapun.");
}

// ============================================================
// GENERATE NOMOR TRANSAKSI
// ============================================================
function generateNoTransaksi($conn)
{
    $prefix = 'TRX' . date('Ymd');
    $like   = $prefix . '%';
    $res    = $conn->prepare("SELECT no_transaksi FROM transaksi WHERE no_transaksi LIKE ? ORDER BY id DESC LIMIT 1");
    $res->bind_param("s", $like);
    $res->execute();
    $row  = $res->get_result()->fetch_assoc();
    $urut = $row ? intval(substr($row['no_transaksi'], -4)) + 1 : 1;
    return $prefix . str_pad($urut, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// PROSES SIMPAN TRANSAKSI
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'transaksi') {

    $nama_pelanggan       = trim($_POST['nama_pelanggan'] ?? '') ?: 'Umum';
    $metode_bayar         = $_POST['metode_bayar'] ?? 'Tunai';
    $rekening_penerima_id = !empty($_POST['rekening_penerima_id']) ? intval($_POST['rekening_penerima_id']) : null;
    $total_bayar          = floatval($_POST['total_bayar'] ?? 0);
    $catatan              = trim($_POST['catatan'] ?? '');
    $items                = json_decode($_POST['items_json'] ?? '[]', true);

    if (!in_array($metode_bayar, ['Tunai', 'Transfer', 'QRIS'])) {
        $metode_bayar = 'Tunai';
    }

    if (empty($items)) {
        $pesan      = 'Keranjang belanja kosong! Tambahkan produk atau layanan terlebih dahulu.';
        $pesan_type = 'danger';
    } elseif ($metode_bayar !== 'Tunai' && empty($rekening_penerima_id)) {
        $pesan      = "Pilih rekening/QRIS tujuan penerimaan pembayaran $metode_bayar!";
        $pesan_type = 'danger';
    } else {
        $total_harga  = 0;
        $detail_items = [];
        $stok_error   = [];

        foreach ($items as $item) {
            $isCustomTopup = !empty($item['is_custom_topup']);
            $qty           = intval($item['qty'] ?? 1);

            if ($isCustomTopup) {
                // Item Top-Up Manual
                $nama_layanan = trim($item['nama_produk'] ?? 'Layanan Saldo Manual');
                $nominalModal = floatval($item['harga_modal'] ?? 0);
                $hargaJual    = floatval($item['harga_jual'] ?? 0);
                $rek_id       = intval($item['rekening_id'] ?? 0);
                $tujuan       = trim($item['tujuan'] ?? '');

                if ($nominalModal <= 0 || $hargaJual <= 0 || $rek_id <= 0) {
                    $stok_error[] = "Data layanan top-up manual tidak lengkap.";
                    continue;
                }

                // Cek saldo di rekening sumber dana
                $cekSaldo = $conn->prepare("SELECT Saldo, Nama_Rekening FROM Rekening WHERE id = ?");
                $cekSaldo->bind_param("i", $rek_id);
                $cekSaldo->execute();
                $rekData = $cekSaldo->get_result()->fetch_assoc();
                $cekSaldo->close();

                $totalModal = $nominalModal * $qty;
                if (!$rekData || $rekData['Saldo'] < $totalModal) {
                    $stok_error[] = "Saldo di {$rekData['Nama_Rekening']} tidak mencukupi (Tersisa: " . formatRupiah($rekData['Saldo'] ?? 0) . ", Butuh: " . formatRupiah($totalModal) . ").";
                    continue;
                }

                $subtotal     = $hargaJual * $qty;
                $total_harga += $subtotal;

                $detail_items[] = [
                    'produk_id'        => null,
                    'nama_produk'      => $nama_layanan,
                    'is_custom_topup'  => true,
                    'harga_modal'      => $nominalModal,
                    'harga_jual'       => $hargaJual,
                    'qty'              => $qty,
                    'subtotal'         => $subtotal,
                    'rekening_id'      => $rek_id,
                    'tujuan'           => $tujuan
                ];

            } else {
                // Item Produk Fisik Biasa
                $pid = intval($item['produk_id'] ?? 0);
                if ($pid <= 0 || $qty <= 0) continue;

                $pstmt = $conn->prepare("SELECT * FROM produk WHERE id = ?");
                $pstmt->bind_param("i", $pid);
                $pstmt->execute();
                $produk = $pstmt->get_result()->fetch_assoc();
                $pstmt->close();

                if (!$produk) {
                    $stok_error[] = "Produk ID $pid tidak ditemukan.";
                    continue;
                }
                if ($produk['status'] !== 'Aktif') {
                    $stok_error[] = "{$produk['nama_produk']} sedang non-aktif.";
                    continue;
                }
                if ($produk['stok'] < $qty) {
                    $stok_error[] = "Stok {$produk['nama_produk']} tidak cukup (sisa {$produk['stok']}).";
                    continue;
                }

                $harga_beli   = floatval($produk['harga_beli']);
                $harga_jual   = floatval($produk['harga_jual']);
                $subtotal     = $harga_jual * $qty;
                $total_harga += $subtotal;

                $detail_items[] = [
                    'produk_id'        => $pid,
                    'nama_produk'      => $produk['nama_produk'],
                    'is_custom_topup'  => false,
                    'harga_modal'      => $harga_beli,
                    'harga_jual'       => $harga_jual,
                    'qty'              => $qty,
                    'subtotal'         => $subtotal,
                    'rekening_id'      => null,
                    'tujuan'           => null
                ];
            }
        }

        if (!empty($stok_error)) {
            $pesan      = implode('<br>', $stok_error);
            $pesan_type = 'danger';
        } elseif (empty($detail_items)) {
            $pesan      = 'Item belanja tidak valid.';
            $pesan_type = 'danger';
        } elseif ($metode_bayar === 'Tunai' && $total_bayar < $total_harga) {
            $pesan      = 'Jumlah pembayaran tunai kurang! Total: ' . formatRupiah($total_harga);
            $pesan_type = 'danger';
        } else {
            if ($metode_bayar !== 'Tunai') {
                $total_bayar = $total_harga;
            }
            $kembalian        = max(0, $total_bayar - $total_harga);
            $no_transaksi     = generateNoTransaksi($conn);
            $user_id          = intval($_SESSION['user_id']);
            $transaksi_cabang = $id_cabang ?: 1;
            $bukti_transfer   = null;

            try {
                if ($metode_bayar !== 'Tunai') {
                    $bukti_transfer = uploadBuktiTransfer('bukti_transfer', $no_transaksi);
                }
            } catch (Exception $e) {
                $pesan = $e->getMessage();
                $pesan_type = 'danger';
            }

            if (!$pesan) {
                $conn->begin_transaction();
                try {
                    // 1. Simpan Header Transaksi
                    $stmt = $conn->prepare(
                        "INSERT INTO transaksi
                            (no_transaksi, Id_cabang, user_id, nama_pelanggan, total_harga, total_bayar, kembalian, metode_bayar, catatan, bukti_transfer)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        "siisdddsss",
                        $no_transaksi,
                        $transaksi_cabang,
                        $user_id,
                        $nama_pelanggan,
                        $total_harga,
                        $total_bayar,
                        $kembalian,
                        $metode_bayar,
                        $catatan,
                        $bukti_transfer
                    );

                    if (!$stmt->execute()) {
                        throw new Exception("Gagal simpan transaksi: " . $stmt->error);
                    }
                    $transaksi_id = $conn->insert_id;
                    $stmt->close();

                    // 2. Simpan Detail Transaksi
                    foreach ($detail_items as $d) {
                        // Jika produk_id kosong/null, kita buat bind-nya fleksibel
                        if (empty($d['produk_id'])) {
                            // Insert tanpa produk_id (dibiarkan NULL)
                            $dstmt = $conn->prepare(
                                "INSERT INTO detail_transaksi
                                    (transaksi_id, produk_id, nama_produk, harga_modal, harga_jual, qty, subtotal, rekening_id, tujuan)
                                VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)"
                            );
                            $dstmt->bind_param(
                                "isddidis",
                                $transaksi_id,
                                $d['nama_produk'],
                                $d['harga_modal'],
                                $d['harga_jual'],
                                $d['qty'],
                                $d['subtotal'],
                                $d['rekening_id'],
                                $d['tujuan']
                            );
                        } else {
                            // Insert untuk produk fisik biasa
                            $dstmt = $conn->prepare(
                                "INSERT INTO detail_transaksi
                                    (transaksi_id, produk_id, nama_produk, harga_modal, harga_jual, qty, subtotal, rekening_id, tujuan)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                            );
                            $dstmt->bind_param(
                                "iisddidis",
                                $transaksi_id,
                                $d['produk_id'],
                                $d['nama_produk'],
                                $d['harga_modal'],
                                $d['harga_jual'],
                                $d['qty'],
                                $d['subtotal'],
                                $d['rekening_id'],
                                $d['tujuan']
                            );
                        }

                        if (!$dstmt->execute()) {
                            throw new Exception("Gagal simpan detail transaksi: " . $dstmt->error);
                        }
                        $dstmt->close();

                        // A. Jika Produk Fisik: Kurangi Stok Barang
                        if (!$d['is_custom_topup'] && !empty($d['produk_id'])) {
                            $sstmt = $conn->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?");
                            $sstmt->bind_param("ii", $d['qty'], $d['produk_id']);
                            $sstmt->execute();
                            $sstmt->close();
                        } 
                        // B. Jika Layanan Saldo: Potong Saldo Rekening Sumber Dana (MUTASI KELUAR)
                        elseif ($d['is_custom_topup'] && !empty($d['rekening_id'])) {
                            $nominal_keluar = $d['harga_modal'] * $d['qty'];
                            $ket = $d['nama_produk'] . " (Tujuan: " . ($d['tujuan'] ?: '-') . ") - No: " . $no_transaksi;
                            catatMutasiRekening($conn, $d['rekening_id'], $transaksi_cabang, $transaksi_id, 'Keluar', $nominal_keluar, $ket);
                        }
                    }

                    // 3. Jika Bayar QRIS/Transfer: Tambah Saldo ke Rekening Penerima Cabang (MUTASI MASUK)
                    if ($metode_bayar !== 'Tunai' && $rekening_penerima_id) {
                        $ketMasuk = "Penerimaan Pembayaran $metode_bayar #$no_transaksi (Pelanggan: $nama_pelanggan)";
                        catatMutasiRekening($conn, $rekening_penerima_id, $transaksi_cabang, $transaksi_id, 'Masuk', $total_harga, $ketMasuk);
                    }

                    $conn->commit();
                    header("Location: transaksi.php?struk=" . $transaksi_id);
                    exit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $bukti_path = $bukti_transfer ? __DIR__ . '/../' . $bukti_transfer : '';
                    if ($bukti_path && file_exists($bukti_path)) {
                        unlink($bukti_path);
                    }
                    $pesan      = 'Transaksi gagal: ' . $e->getMessage();
                    $pesan_type = 'danger';
                }
            }
        }
    }
}

// ============================================================
// AMBIL STRUK TRANSAKSI
// ============================================================
$struk        = null;
$struk_detail = [];
if (!empty($_GET['struk']) && intval($_GET['struk']) > 0) {
    $sid   = intval($_GET['struk']);
    $sstmt = $conn->prepare(
        "SELECT t.*, u.nama_lengkap, c.Nama_cabang
         FROM transaksi t 
         JOIN users u ON t.user_id = u.id 
         LEFT JOIN Cabang c ON t.Id_cabang = c.id
         WHERE t.id = ?"
    );
    $sstmt->bind_param("i", $sid);
    $sstmt->execute();
    $struk = $sstmt->get_result()->fetch_assoc();
    $sstmt->close();

    if ($struk) {
        $dstmt2 = $conn->prepare("SELECT * FROM detail_transaksi WHERE transaksi_id = ? ORDER BY id ASC");
        $dstmt2->bind_param("i", $sid);
        $dstmt2->execute();
        $struk_detail = $dstmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $dstmt2->close();
    }
}

// ============================================================
// PRODUK & REKENING AKTIF CABANG INI
// ============================================================
$prod_sql = "SELECT id, kode_produk, nama_produk, kategori, merk, harga_jual, stok, satuan 
             FROM produk 
             WHERE status='Aktif' AND kategori != 'Layanan Saldo' " . ($id_cabang ? "AND Id_cabang = '$id_cabang'" : "") . " 
             ORDER BY nama_produk ASC";
$produks = $conn->query($prod_sql)->fetch_all(MYSQLI_ASSOC);

// Rekening Aktif Cabang / Pusat
$rek_sql = "SELECT id, Nama_Rekening, No_rek, Saldo, Tipe, id_cabang 
            FROM Rekening 
            WHERE id_cabang = '$id_cabang' OR id_cabang IS NULL 
            ORDER BY Nama_Rekening ASC";
$rekenings = $conn->query($rek_sql)->fetch_all(MYSQLI_ASSOC);

// Riwayat Transaksi Cabang
$riw_sql = "SELECT t.*, u.nama_lengkap FROM transaksi t
            JOIN users u ON t.user_id = u.id 
            " . ($id_cabang ? "WHERE t.Id_cabang = '$id_cabang'" : "") . " 
            ORDER BY t.id DESC LIMIT 15";
$riwayat = $conn->query($riw_sql)->fetch_all(MYSQLI_ASSOC);
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
            body>* { display: none !important; }
            #printArea { display: block !important; position: fixed; inset: 0; background: #fff; z-index: 9999; padding: 8px; font-family: monospace; font-size: 12px; }
        }
        #printArea { display: none; }
    </style>
</head>

<body class="kasir-modern mobile-card-tables">

    <?php include '../layout/sidebar.php'; ?>[cite: 4]
    <?php include '../layout/navbar.php'; ?>[cite: 4]
    <div class="overlay" id="overlay"></div>

    <!-- AREA CETAK STRUK -->
    <div id="printArea">
        <?php if ($struk): ?>
            <div style="width:280px;margin:0 auto;font-family:monospace;font-size:12px;">
                <div style="text-align:center;margin-bottom:6px;">
                    <div style="font-weight:bold;font-size:15px;">★ FEDLY CELL ★</div>
                    <div style="font-size:11px;"><?= htmlspecialchars($struk['Nama_cabang'] ?? 'Cabang') ?></div>
                    <div style="border-top:1px dashed #000;margin:5px 0;"></div>
                </div>
                <table style="width:100%;font-size:11px;border-collapse:collapse;">
                    <tr><td>No. TRX</td><td style="text-align:right;font-weight:bold;"><?= htmlspecialchars($struk['no_transaksi']) ?></td></tr>
                    <tr><td>Tanggal</td><td style="text-align:right;"><?= date('d/m/Y H:i', strtotime($struk['created_at'])) ?></td></tr>
                    <tr><td>Kasir</td><td style="text-align:right;"><?= htmlspecialchars($struk['nama_lengkap']) ?></td></tr>
                    <tr><td>Pelanggan</td><td style="text-align:right;"><?= htmlspecialchars($struk['nama_pelanggan']) ?></td></tr>
                </table>
                <div style="border-top:1px dashed #000;margin:5px 0;"></div>
                <?php foreach ($struk_detail as $sd): ?>
                    <div style="margin-bottom:4px;font-size:11px;">
                        <div style="font-weight:bold;"><?= htmlspecialchars($sd['nama_produk']) ?></div>
                        <?php if ($sd['tujuan']): ?>
                            <div style="font-size:10px;color:#333;">Tujuan: <?= htmlspecialchars($sd['tujuan']) ?></div>
                        <?php endif; ?>
                        <table style="width:100%;">
                            <tr>
                                <td><?= $sd['qty'] ?> x <?= formatRupiah($sd['harga_jual']) ?></td>
                                <td style="text-align:right;"><?= formatRupiah($sd['subtotal']) ?></td>
                            </tr>
                        </table>
                    </div>
                <?php endforeach; ?>
                <div style="border-top:1px dashed #000;margin:5px 0;"></div>
                <table style="width:100%;font-size:11px;">
                    <tr><td style="font-weight:bold;">TOTAL</td><td style="text-align:right;font-weight:bold;"><?= formatRupiah($struk['total_harga']) ?></td></tr>
                    <tr><td>Bayar (<?= $struk['metode_bayar'] ?>)</td><td style="text-align:right;"><?= formatRupiah($struk['total_bayar']) ?></td></tr>
                    <tr><td style="font-weight:bold;">Kembalian</td><td style="text-align:right;font-weight:bold;"><?= formatRupiah($struk['kembalian']) ?></td></tr>
                </table>
                <div style="border-top:1px dashed #000;margin:5px 0;"></div>
                <div style="text-align:center;font-size:10px;color:#555;">Terima kasih atas kunjungan Anda!</div>
            </div>
        <?php endif; ?>
    </div>

    <main class="main-content">
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="fw-bold mb-1">Kasir POS</h2>
                <p class="text-muted mb-0">Cabang Penugasan: <strong><?= htmlspecialchars($_SESSION['nama_cabang'] ?? 'Cabang') ?></strong></p>
            </div>
            <div>
                <button type="button" class="btn btn-warning shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modalTopupManual">
                    <i class="fas fa-bolt me-2"></i>+ Layanan Top-Up / Transfer
                </button>
            </div>
        </div>

        <?php if ($pesan): ?>
            <div class="alert alert-<?= $pesan_type ?> alert-dismissible fade show" role="alert">
                <?= $pesan ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <!-- KIRI: DAFTAR PRODUK FISIK -->
            <div class="col-lg-7">
                <div class="table-container h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-box me-2 text-primary"></i>Pilih Produk Fisik</h5>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="cariProduk" placeholder="Cari nama atau barcode...">
                            </div>
                        </div>
                        <div class="col-5">
                            <select class="form-select" id="filterKategori">
                                <option value="">Semua Kategori</option>
                                <option value="Aksesoris HP">Aksesoris HP</option>
                                <option value="Voucher Internet">Voucher Internet</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height:420px; overflow-y:auto;">
                        <table class="table table-hover table-sm align-middle" id="tblProduk">
                            <thead class="sticky-top bg-light">
                                <tr>
                                    <th>Produk</th>
                                    <th>Kategori</th>
                                    <th>Harga</th>
                                    <th>Stok</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($produks)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3">Tidak ada produk fisik tersedia.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($produks as $p): ?>
                                        <tr class="produk-row"
                                            data-id="<?= $p['id'] ?>"
                                            data-nama="<?= htmlspecialchars($p['nama_produk']) ?>"
                                            data-kode="<?= htmlspecialchars($p['kode_produk']) ?>"
                                            data-kategori="<?= htmlspecialchars($p['kategori']) ?>"
                                            data-harga="<?= floatval($p['harga_jual']) ?>"
                                            data-stok="<?= $p['stok'] ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($p['nama_produk']) ?></strong>
                                                <br><small class="text-muted"><?= htmlspecialchars($p['kode_produk']) ?></small>
                                            </td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($p['kategori']) ?></span></td>
                                            <td class="text-nowrap fw-bold"><?= formatRupiah($p['harga_jual']) ?></td>
                                            <td>
                                                <?php if ($p['stok'] < 5): ?>
                                                    <span class="badge bg-warning text-dark"><?= $p['stok'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><?= $p['stok'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-primary btn-sm btnTambahKeranjang">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- KANAN: KERANJANG BELANJA & PEMBAYARAN -->
            <div class="col-lg-5">
                <div class="table-container h-100">
                    <h5 class="mb-3"><i class="fas fa-shopping-cart me-2 text-primary"></i>Keranjang Belanja</h5>
                    <div class="table-responsive mb-3" style="max-height:280px; overflow-y:auto;">
                        <table class="table table-sm align-middle" id="tblKeranjang">
                            <thead class="sticky-top bg-light">
                                <tr>
                                    <th>Item Transaksi</th>
                                    <th style="width:75px;">Qty</th>
                                    <th>Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="keranjangBody">
                                <tr id="keranjangKosong">
                                    <td colspan="4" class="text-center text-muted py-3">Keranjang masih kosong</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between fw-bold fs-5 border-top pt-2 mb-3">
                        <span>Total Tagihan:</span>
                        <span id="labelTotal" class="text-primary">Rp 0</span>
                    </div>

                    <form method="POST" action="transaksi.php" id="formTransaksi" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="transaksi">
                        <input type="hidden" name="items_json" id="inputItemsJson">
                        <input type="hidden" name="total_bayar" id="hiddenTotalBayar" value="0">

                        <div class="mb-2">
                            <label class="form-label small">Nama Pelanggan</label>
                            <input type="text" class="form-control form-control-sm" name="nama_pelanggan" value="Umum">
                        </div>

                        <div class="row mb-2">
                            <div class="col-6">
                                <label class="form-label small">Metode Bayar</label>
                                <select class="form-select form-select-sm" name="metode_bayar" id="metodeBayar">
                                    <option value="Tunai">Tunai (Cash)</option>
                                    <option value="QRIS">QRIS Cabang</option>
                                    <option value="Transfer">Transfer Bank</option>
                                </select>
                            </div>
                            <div class="col-6" id="wrapBayar">
                                <label class="form-label small">Uang Diterima</label>
                                <input type="number" class="form-control form-control-sm" id="inputBayar" min="0" placeholder="0">
                            </div>
                        </div>

                        <!-- Dropdown Rekening Penerima QRIS/Transfer Cabang -->
                        <div class="mb-2" id="wrapRekeningPenerima" style="display:none;">
                            <label class="form-label small text-primary fw-bold">Rekening/QRIS Penerima Saldo Masuk <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" name="rekening_penerima_id" id="selectRekeningPenerima">
                                <option value="">-- Pilih Rekening/QRIS Cabang --</option>
                                <?php foreach ($rekenings as $rek): ?>
                                    <option value="<?= $rek['id'] ?>">
                                        <?= htmlspecialchars($rek['Nama_Rekening']) ?> (<?= htmlspecialchars($rek['No_rek']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" style="font-size: 11px;">Saldo akun ini akan otomatis bertambah saat transaksi disimpan.</small>
                        </div>

                        <div class="d-flex justify-content-between mb-2 text-success fw-bold" id="wrapKembalian" style="display:none;">
                            <span>Kembalian:</span>
                            <span id="labelKembalian">Rp 0</span>
                        </div>

                        <div class="mb-3" id="wrapBuktiTransfer" style="display:none;">
                            <label class="form-label small">Upload Bukti Transfer / Resi QRIS <span class="text-danger">*</span></label>
                            <input type="file" class="form-control form-control-sm" name="bukti_transfer" id="inputBuktiTransfer" accept="image/*">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small">Catatan Transaksi (Opsional)</label>
                            <textarea class="form-control form-control-sm" name="catatan" rows="1"></textarea>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-success" id="btnProses">
                                <i class="fas fa-check-circle me-2"></i>Proses Pembayaran
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnKosongkan">
                                <i class="fas fa-trash me-1"></i>Kosongkan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- RIWAYAT TRANSAKSI TERBARU -->
        <div class="row">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="mb-3"><i class="fas fa-history me-2 text-primary"></i>Riwayat Transaksi Terbaru Cabang</h5>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No Transaksi</th>
                                    <th>Pelanggan</th>
                                    <th>Total</th>
                                    <th>Metode</th>
                                    <th>Kasir</th>
                                    <th>Waktu</th>
                                    <th>Struk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($riwayat)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-3">Belum ada transaksi di cabang ini.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($riwayat as $r): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars($r['no_transaksi']) ?></code></td>
                                            <td><?= htmlspecialchars($r['nama_pelanggan']) ?></td>
                                            <td class="fw-bold"><?= formatRupiah($r['total_harga']) ?></td>
                                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($r['metode_bayar']) ?></span></td>
                                            <td><?= htmlspecialchars($r['nama_lengkap']) ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                                            <td>
                                                <a href="transaksi.php?struk=<?= $r['id'] ?>" class="btn btn-info btn-sm text-white" title="Lihat Struk">
                                                    <i class="fas fa-receipt"></i>
                                                </a>
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

    <!-- ========== MODAL INPUT LAYANAN TOP-UP MANUAL ========== -->
    <div class="modal fade" id="modalTopupManual" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="fas fa-bolt me-2"></i>Layanan Top-Up / Transfer Manual</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formTopupManual">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Jenis Layanan / E-Wallet <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="modalNamaLayanan" placeholder="Contoh: Top Up DANA, Transfer BCA, Pulsa Tsel" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">No. HP / Rekening Tujuan <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="modalNoTujuan" placeholder="Contoh: 081234567890 / 8830123xxx" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Nominal Top-Up (Modal Rp) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="modalNominalModal" min="1000" placeholder="Contoh: 50000" required>
                                <small class="text-muted" style="font-size:11px;">Saldo toko yang terpotong.</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Harga Tagihan ke Pelanggan (Rp) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="modalHargaJual" min="1000" placeholder="Contoh: 52500" required>
                                <small class="text-muted" style="font-size:11px;">Nominal + Biaya Admin.</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Rekening Sumber Dana (Saldo Toko) <span class="text-danger">*</span></label>
                            <select class="form-select" id="modalSumberDana" required>
                                <option value="">-- Pilih Akun Sumber Dana --</option>
                                <?php foreach ($rekenings as $rek): ?>
                                    <option value="<?= $rek['id'] ?>" data-saldo="<?= $rek['Saldo'] ?>" data-nama="<?= htmlspecialchars($rek['Nama_Rekening']) ?>">
                                        <?= htmlspecialchars($rek['Nama_Rekening']) ?> (Sisa: <?= formatRupiah($rek['Saldo']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-cart-plus me-1"></i>Masukkan ke Keranjang</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL STRUK -->
    <div class="modal fade" id="modalStruk" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Transaksi Sukses</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($struk): ?>
                        <div class="text-center mb-3">
                            <h6 class="fw-bold mb-0">Fedly Cell</h6>
                            <small class="text-muted"><?= htmlspecialchars($struk['Nama_cabang'] ?? 'Cabang') ?></small>
                        </div>
                        <div class="small mb-2 border-bottom pb-2">
                            <div class="d-flex justify-content-between"><span>No TRX:</span><strong><?= htmlspecialchars($struk['no_transaksi']) ?></strong></div>
                            <div class="d-flex justify-content-between"><span>Kasir:</span><span><?= htmlspecialchars($struk['nama_lengkap']) ?></span></div>
                        </div>
                        <?php foreach ($struk_detail as $sd): ?>
                            <div class="small mb-1">
                                <div><?= htmlspecialchars($sd['nama_produk']) ?></div>
                                <div class="d-flex justify-content-between text-muted">
                                    <span><?= $sd['qty'] ?>x</span>
                                    <span><?= formatRupiah($sd['subtotal']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="border-top pt-2 mt-2 small">
                            <div class="d-flex justify-content-between fw-bold"><span>Total:</span><span><?= formatRupiah($struk['total_harga']) ?></span></div>
                            <div class="d-flex justify-content-between"><span>Kembalian:</span><span><?= formatRupiah($struk['kembalian']) ?></span></div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <a href="transaksi.php" class="btn btn-secondary btn-sm">Transaksi Baru</a>
                    <button class="btn btn-primary btn-sm" id="btnCetak"><i class="fas fa-print me-1"></i>Cetak</button>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../asset/script.js"></script>
    <script>
        let keranjang = {};

        // Cari Produk Fisik
        // Fungsi Filter Gabungan Teks & Kategori
        function filterDaftarProduk() {
            const q = document.getElementById('cariProduk').value.toLowerCase().trim();
            const kat = document.getElementById('filterKategori').value;

            document.querySelectorAll('.produk-row').forEach(row => {
                const nama = row.dataset.nama ? row.dataset.nama.toLowerCase() : '';
                const kode = row.dataset.kode ? row.dataset.kode.toLowerCase() : '';
                const kategoriRow = row.dataset.kategori || '';

                // Cek kecocokan teks
                const cocokTeks = (nama.includes(q) || kode.includes(q));
                // Cek kecocokan kategori
                const cocokKategori = (kat === '' || kategoriRow === kat);

                // Tampilkan hanya jika teks DAN kategori cocok
                row.style.display = (cocokTeks && cocokKategori) ? '' : 'none';
            });
        }

        // Pasang Event Listener ke Input Teks & Dropdown Kategori
        document.getElementById('cariProduk').addEventListener('input', filterDaftarProduk);
        document.getElementById('filterKategori').addEventListener('change', filterDaftarProduk);

        // 1. Tambah Produk Fisik ke Keranjang
        document.querySelectorAll('.btnTambahKeranjang').forEach(btn => {
            btn.addEventListener('click', function() {
                const row = this.closest('.produk-row');
                const id = 'prod_' + row.dataset.id;
                const pid = parseInt(row.dataset.id);
                const nama = row.dataset.nama;
                const harga = parseFloat(row.dataset.harga);
                const stok = parseInt(row.dataset.stok);

                if (keranjang[id]) {
                    if (keranjang[id].qty >= stok) {
                        alert('Stok ' + nama + ' tidak mencukupi!');
                        return;
                    }
                    keranjang[id].qty++;
                } else {
                    keranjang[id] = {
                        is_custom_topup: false,
                        produk_id: pid,
                        nama_produk: nama,
                        harga_jual: harga,
                        qty: 1,
                        stok: stok
                    };
                }
                renderKeranjang();
            });
        });

        // 2. Tambah Layanan Top-Up Manual dari Modal
        document.getElementById('formTopupManual').addEventListener('submit', function(e) {
            e.preventDefault();
            const namaLayanan  = document.getElementById('modalNamaLayanan').value.trim();
            const noTujuan     = document.getElementById('modalNoTujuan').value.trim();
            const nominalModal = parseFloat(document.getElementById('modalNominalModal').value) || 0;
            const hargaJual    = parseFloat(document.getElementById('modalHargaJual').value) || 0;
            const selSumber    = document.getElementById('modalSumberDana');
            const rekId        = parseInt(selSumber.value) || 0;
            const saldoRek     = parseFloat(selSumber.options[selSumber.selectedIndex].dataset.saldo) || 0;
            const namaRek      = selSumber.options[selSumber.selectedIndex].dataset.nama;

            if (saldoRek < nominalModal) {
                alert(`Saldo pada ${namaRek} tidak mencukupi! (Sisa: Rp ${fmt(saldoRek)}, Butuh: Rp ${fmt(nominalModal)})`);
                return;
            }

            const uniqueId = 'topup_' + Date.now();
            keranjang[uniqueId] = {
                is_custom_topup: true,
                produk_id: null,
                nama_produk: namaLayanan,
                tujuan: noTujuan,
                harga_modal: nominalModal,
                harga_jual: hargaJual,
                rekening_id: rekId,
                nama_rekening: namaRek,
                qty: 1
            };

            // Reset Form & Tutup Modal
            this.reset();
            bootstrap.Modal.getInstance(document.getElementById('modalTopupManual')).hide();
            renderKeranjang();
        });

        function renderKeranjang() {
            const tbody = document.getElementById('keranjangBody');
            const keys = Object.keys(keranjang);

            if (keys.length === 0) {
                tbody.innerHTML = '<tr id="keranjangKosong"><td colspan="4" class="text-center text-muted py-3">Keranjang masih kosong</td></tr>';
                updateTotal();
                return;
            }

            let html = '';
            keys.forEach(id => {
                const item = keranjang[id];
                const sub = item.harga_jual * item.qty;

                html += `<tr>
                <td class="small">
                    <strong>${item.nama_produk}</strong>
                    ${item.is_custom_topup ? `
                        <div class="text-muted" style="font-size:11px;">
                            <span>Tujuan: <strong>${item.tujuan}</strong></span><br>
                            <span>Sumber: ${item.nama_rekening} (Modal: Rp ${fmt(item.harga_modal)})</span>
                        </div>
                    ` : ''}
                </td>
                <td>
                    ${!item.is_custom_topup ? `
                    <div class="input-group input-group-sm" style="width:75px;">
                        <button type="button" class="btn btn-outline-secondary btn-sm px-1 btnMin" data-id="${id}">-</button>
                        <input type="number" class="form-control form-control-sm text-center px-0 inputQty" value="${item.qty}" min="1" data-id="${id}" style="width:30px;">
                        <button type="button" class="btn btn-outline-secondary btn-sm px-1 btnPlus" data-id="${id}">+</button>
                    </div>` : `<span class="badge bg-light text-dark border">1x</span>`}
                </td>
                <td class="small text-nowrap fw-bold">Rp ${fmt(sub)}</td>
                <td>
                    <button type="button" class="btn btn-danger btn-sm py-0 btnHapusItem" data-id="${id}"><i class="fas fa-times"></i></button>
                </td>
            </tr>`;
            });
            tbody.innerHTML = html;

            tbody.querySelectorAll('.btnMin').forEach(b => b.addEventListener('click', function() {
                if (keranjang[this.dataset.id].qty > 1) {
                    keranjang[this.dataset.id].qty--;
                    renderKeranjang();
                }
            }));
            tbody.querySelectorAll('.btnPlus').forEach(b => b.addEventListener('click', function() {
                const id = this.dataset.id;
                if (keranjang[id].qty < keranjang[id].stok) {
                    keranjang[id].qty++;
                    renderKeranjang();
                } else alert('Stok tidak mencukupi!');
            }));
            tbody.querySelectorAll('.inputQty').forEach(inp => inp.addEventListener('change', function() {
                const id = this.dataset.id, val = parseInt(this.value);
                if (val < 1) keranjang[id].qty = 1;
                else if (val > keranjang[id].stok) keranjang[id].qty = keranjang[id].stok;
                else keranjang[id].qty = val;
                renderKeranjang();
            }));
            tbody.querySelectorAll('.btnHapusItem').forEach(b => b.addEventListener('click', function() {
                delete keranjang[this.dataset.id];
                renderKeranjang();
            }));

            updateTotal();
        }

        function getTotalHarga() {
            return Object.values(keranjang).reduce((s, i) => s + i.harga_jual * i.qty, 0);
        }

        function updateTotal() {
            document.getElementById('labelTotal').textContent = 'Rp ' + fmt(getTotalHarga());
            hitungKembalian();
        }

        function hitungKembalian() {
            const total = getTotalHarga();
            const bayar = parseFloat(document.getElementById('inputBayar').value) || 0;
            const metode = document.getElementById('metodeBayar').value;
            const wrap = document.getElementById('wrapKembalian');
            if (metode === 'Tunai' && bayar > 0) {
                document.getElementById('labelKembalian').textContent = 'Rp ' + fmt(Math.max(0, bayar - total));
                wrap.style.display = 'flex';
            } else {
                wrap.style.display = 'none';
            }
        }

        document.getElementById('inputBayar').addEventListener('input', hitungKembalian);
        document.getElementById('metodeBayar').addEventListener('change', function() {
            const isTunai = this.value === 'Tunai';
            document.getElementById('wrapBayar').style.display = isTunai ? '' : 'none';
            document.getElementById('wrapRekeningPenerima').style.display = isTunai ? 'none' : '';
            document.getElementById('wrapBuktiTransfer').style.display = isTunai ? 'none' : '';
            document.getElementById('inputBuktiTransfer').required = !isTunai;
            document.getElementById('selectRekeningPenerima').required = !isTunai;
            hitungKembalian();
        });

        document.getElementById('btnKosongkan').addEventListener('click', function() {
            if (confirm('Kosongkan keranjang belanja?')) {
                keranjang = {};
                renderKeranjang();
            }
        });

        document.getElementById('btnProses').addEventListener('click', function() {
            if (!Object.keys(keranjang).length) {
                alert('Keranjang masih kosong!');
                return;
            }
            const metode = document.getElementById('metodeBayar').value;
            const total = getTotalHarga();
            const bayar = parseFloat(document.getElementById('inputBayar').value) || 0;

            if (metode === 'Tunai' && bayar < total) {
                alert('Jumlah bayar kurang!\nTotal: Rp ' + fmt(total));
                document.getElementById('inputBayar').focus();
                return;
            }
            if (metode !== 'Tunai') {
                if (!document.getElementById('selectRekeningPenerima').value) {
                    alert('Pilih rekening penerima QRIS/Transfer terlebih dahulu.');
                    document.getElementById('selectRekeningPenerima').focus();
                    return;
                }
                if (!document.getElementById('inputBuktiTransfer').files.length) {
                    alert('Silakan upload bukti transfer/QRIS!');
                    return;
                }
            }

            document.getElementById('inputItemsJson').value = JSON.stringify(Object.values(keranjang));
            document.getElementById('hiddenTotalBayar').value = (metode === 'Tunai') ? bayar : total;
            document.getElementById('formTransaksi').submit();
        });

        document.getElementById('btnCetak').addEventListener('click', function() {
            document.getElementById('printArea').style.display = 'block';
            window.print();
            setTimeout(() => { document.getElementById('printArea').style.display = 'none'; }, 1000);
        });

        function fmt(n) { return parseInt(n).toLocaleString('id-ID'); }

        <?php if ($struk): ?>
            document.addEventListener('DOMContentLoaded', function() {
                new bootstrap.Modal(document.getElementById('modalStruk')).show();
            });
        <?php endif; ?>
    </script>
</body>

</html>