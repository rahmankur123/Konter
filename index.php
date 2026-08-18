<?php
// login.php
require_once 'config.php';

// Jika sudah login, langsung lempar ke dashboard masing-masing
if (isLoggedIn()) {
    if ($_SESSION['role'] === 'kasir') {
        redirect('kasir/dashboard.php');
    } else {
        redirect('pemilik/dashboard.php');
    }
}

$alertMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $alertMessage = "Username dan password wajib diisi!";
    } else {
        $conn = getConnection();
        
        // Ambil data user beserta nama cabangnya (jika ada)
        $stmt = $conn->prepare("
            SELECT u.*, c.Nama_cabang 
            FROM users u
            LEFT JOIN Cabang c ON u.id_cabang = c.id
            WHERE u.username = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        // Cek kecocokan password (mendukung password_hash() maupun plain-text TA lama)
        $isPasswordValid = false;
        if ($user) {
            if (password_verify($password, $user['password']) || $password === $user['password']) {
                $isPasswordValid = true;
            }
        }

        if ($isPasswordValid) {
            // Set session utama via helper config.php
            setLoginSession($user);

            // Simpan info tambahan nama cabang untuk kebutuhan UI kasir
            $_SESSION['nama_cabang'] = $user['Nama_cabang'] ?? 'Pusat / Semua Cabang';

            $stmt->close();
            $conn->close();

            // Redirect sesuai role
            if ($user['role'] === 'kasir') {
                redirect('kasir/dashboard.php');
            } else {
                redirect('pemilik/dashboard.php');
            }
        } else {
            $alertMessage = "Username atau password salah!";
            $stmt->close();
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Fedly Cell POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="./asset/login-style.css">
</head>
<body>

<div class="login-wrapper">
    <div class="bg-decoration"></div>
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-12 col-md-10 col-lg-8 col-xl-6">
                <div class="login-card">
                    <div class="row g-0">
                        <div class="col-md-5 d-none d-md-block">
                            <div class="brand-section">
                                <div class="brand-content">
                                    <div class="brand-icon">
                                        <i class="fas fa-mobile-alt"></i>
                                    </div>
                                    <h2>Fedly Cell</h2>
                                    <p class="brand-subtitle">Point Of Sales & Multi Cabang</p>
                                    <div class="brand-features">
                                        <div class="feature-item"><i class="fas fa-check-circle"></i><span>Multi Cabang</span></div>
                                        <div class="feature-item"><i class="fas fa-check-circle"></i><span>Top Up & Transfer</span></div>
                                        <div class="feature-item"><i class="fas fa-check-circle"></i><span>Mutasi Saldo Real-time</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="login-section">
                                <div class="mobile-logo d-md-none text-center mb-4">
                                    <i class="fas fa-mobile-alt fa-3x text-primary mb-2"></i>
                                    <h3 class="fw-bold">Fedly Cell POS</h3>
                                </div>
                                <h3 class="login-title">Selamat Datang!</h3>
                                <p class="login-subtitle">Silakan login untuk melanjutkan</p>

                                <?php if($alertMessage): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <?= htmlspecialchars($alertMessage) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                                <?php endif; ?>

                                <form id="loginForm" method="POST" action="">
                                    <div class="form-group mb-3">
                                        <label for="username" class="form-label"><i class="fas fa-user me-2"></i>Username</label>
                                        <div class="input-wrapper">
                                            <i class="fas fa-user input-icon"></i>
                                            <input type="text" class="form-control" id="username" name="username" placeholder="Masukkan username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username">
                                        </div>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="password" class="form-label"><i class="fas fa-lock me-2"></i>Password</label>
                                        <div class="input-wrapper">
                                            <i class="fas fa-lock input-icon"></i>
                                            <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" required autocomplete="current-password">
                                            <button type="button" class="toggle-password" id="togglePassword"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn-login" id="submitBtn">
                                        <span class="btn-text">Login</span>
                                        <span class="btn-loader" style="display: none;"><i class="fas fa-spinner fa-spin"></i> Memproses...</span>
                                    </button>
                                </form>

                                <div class="login-footer">
                                    <p class="mb-0"><small>&copy; 2026 Fedly Cell. All rights reserved.</small></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Toggle visibility password
document.getElementById('togglePassword').addEventListener('click', function() {
    const pw = document.getElementById('password');
    const icon = this.querySelector('i');
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
    pw.type = pw.type === 'password' ? 'text' : 'password';
});

// Efek loading saat tombol submit ditekan
document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.querySelector('.btn-text').style.display = 'none';
    btn.querySelector('.btn-loader').style.display = 'inline-block';
});
</script>

</body>
</html>