<?php
// ==========================================
// 1. SESSION MANAGEMENT & ROLE ISOLATION
// ==========================================

function sessionNameForRole($role = null) {
    if ($role === 'kasir') {
        return 'FEDLY_KASIR_SESS';
    }
    if ($role === 'pemilik') {
        return 'FEDLY_PEMILIK_SESS';
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($script, '/kasir/') !== false) {
        return 'FEDLY_KASIR_SESS';
    }
    if (strpos($script, '/pemilik/') !== false) {
        return 'FEDLY_PEMILIK_SESS';
    }
    return 'FEDLY_LOGIN_SESS';
}

function startFedlySession($role = null) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(sessionNameForRole($role));
    session_set_cookie_params(0, '/', '', false, true);
    session_start();
}

function setLoginSession($user) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    startFedlySession($user['role']);
    session_regenerate_id(true);
    
    $_SESSION['user_id']      = $user['id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
    $_SESSION['role']         = $user['role'];
    $_SESSION['id_cabang']     = $user['id_cabang']; // Kasir: ID cabang, Pemilik: NULL
    $_SESSION['filter_cabang_id'] = null; // Default filter untuk pemilik (null = semua cabang)
}

function destroyRoleSession($role = null) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    startFedlySession($role);
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), 
            '', 
            time() - 42000, 
            $params['path'], 
            $params['domain'], 
            $params['secure'], 
            $params['httponly']
        );
    }

    session_destroy();
}

// Inisialisasi Sesi
startFedlySession();

// ==========================================
// 2. DATABASE CONFIGURATION & CONNECTION
// ==========================================

define('DB_HOST', 'localhost');
define('DB_USER', 'hospital_konterfedly');
define('DB_PASS', 'konterfedly1');
define('DB_NAME', 'hospital_konter');

function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Koneksi database gagal: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

// ==========================================
// 3. AUTH & ACCESS CONTROL HELPERS
// ==========================================

function redirect($url) {
    header("Location: $url");
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function checkRole($allowedRoles = []) {
    if (!isLoggedIn()) {
        redirect('../login.php');
    }
    if (!empty($allowedRoles) && !in_array($_SESSION['role'], $allowedRoles)) {
        redirect('../login.php');
    }
}

// Mendapatkan ID Cabang aktif (Kasir mengikuti tugasnya, Pemilik mengikuti filternya)
function getActiveCabangId() {
    if (!isset($_SESSION['role'])) {
        return null;
    }
    
    if ($_SESSION['role'] === 'kasir') {
        return $_SESSION['id_cabang'];
    }
    
    if ($_SESSION['role'] === 'pemilik' && !empty($_SESSION['filter_cabang_id'])) {
        return $_SESSION['filter_cabang_id'];
    }
    
    return null; // Null berarti Semua Cabang (khusus Pemilik)
}

// ==========================================
// 4. FORMATTING & LOGICAL HELPERS
// ==========================================

function formatRupiah($angka) {
    return "Rp " . number_format($angka, 0, ',', '.');
}

// Helper mutasi saldo rekening (Buku Kas Saldo)
function catatMutasiRekening($conn, $idSaldo, $idCabang, $idTransaksi, $jenisMutasi, $jumlah, $keterangan) {
    // 1. Kunci dan ambil saldo berjalan
    $stmt = $conn->prepare("SELECT Saldo FROM Rekening WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $idSaldo);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    if (!$res) {
        throw new Exception("Rekening sumber dana tidak ditemukan.");
    }

    $saldoAwal = (float) $res['Saldo'];
    $jumlah    = (float) $jumlah;
    
    if ($jenisMutasi === 'Keluar' && $saldoAwal < $jumlah) {
        throw new Exception("Saldo di rekening tidak mencukupi untuk transaksi ini.");
    }

    $saldoAkhir = ($jenisMutasi === 'Masuk') ? ($saldoAwal + $jumlah) : ($saldoAwal - $jumlah);

    // 2. Update saldo pada master Rekening
    $upStmt = $conn->prepare("UPDATE Rekening SET Saldo = ? WHERE id = ?");
    $upStmt->bind_param("di", $saldoAkhir, $idSaldo);
    $upStmt->execute();

    // 3. Catat riwayat ke Mutasi_rekening
    $insStmt = $conn->prepare(
        "INSERT INTO Mutasi_rekening (Id_saldo, id_cabang, id_transaksi, jenis_mutasi, Jumlah, saldo_awal, saldo_akhir, Keterangan) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insStmt->bind_param("iiisddds", $idSaldo, $idCabang, $idTransaksi, $jenisMutasi, $jumlah, $saldoAwal, $saldoAkhir, $keterangan);
    $insStmt->execute();

    return true;
}

// ==========================================
// 5. FILE UPLOAD HELPERS (BUKTI BAYAR)
// ==========================================

function ensureBuktiTransferColumn($conn) {
    $result = $conn->query("SHOW COLUMNS FROM transaksi LIKE 'bukti_transfer'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE transaksi ADD bukti_transfer VARCHAR(255) NULL AFTER catatan");
    }
}

function uploadBuktiTransfer($fieldName, $noTransaksi) {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Bukti transfer/QRIS wajib diunggah untuk metode pembayaran non-tunai.');
    }

    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Gagal mengunggah bukti pembayaran. Silakan coba lagi.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('Ukuran file bukti pembayaran maksimal 5MB.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new Exception('Format bukti pembayaran harus JPG, PNG, atau WEBP.');
    }

    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'asset' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bukti-transfer';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new Exception('Folder direktori upload bukti pembayaran tidak dapat dibuat.');
    }

    $filename = $noTransaksi . '_' . date('His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target   = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception('Gagal menyimpan file bukti pembayaran ke server.');
    }

    return 'asset/uploads/bukti-transfer/' . $filename;
}

function buktiTransferUrl($path) {
    if (!$path) {
        return '';
    }
    return '../' . ltrim($path, '/\\');
}
?>