<?php
// login.php
require_once 'config.php';

// Jika pengguna sudah login, langsung alihkan ke dashboard masing-masing
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

        // Ambil data pengguna beserta nama cabang penugasannya
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

        // Validasi password (mendukung password_hash() dan fallback plaintext)
        $isPasswordValid = false;
        if ($user) {
            if (password_verify($password, $user['password']) || $password === $user['password']) {
                $isPasswordValid = true;
            }
        }

        if ($isPasswordValid) {
            // Set session dari config.php
            setLoginSession($user);

            // Simpan info nama cabang untuk display di Navbar / Header
            $_SESSION['nama_cabang'] = $user['Nama_cabang'] ?? 'Pusat / Semua Cabang';

            $stmt->close();
            $conn->close();

            // Redirect sesuai role masing-masing
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #130f40 0%, #2a0845 50%, #1e1248 100%);
            --card-bg: rgba(30, 24, 75, 0.75);
            --card-border: rgba(142, 68, 173, 0.35);
            --input-bg: rgba(19, 15, 64, 0.85);
            --input-border: rgba(155, 89, 182, 0.4);
            --primary-accent: #00d2d3;
            --btn-gradient: linear-gradient(135deg, #0984e3 0%, #6c5ce7 100%);
            --btn-hover: linear-gradient(135deg, #00cec9 0%, #a29bfe 100%);
        }

        * {
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background: var(--bg-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            overflow-x: hidden;
            position: relative;
        }

        /* Background glow effect */
        body::before {
            content: '';
            position: absolute;
            width: 380px;
            height: 380px;
            background: rgba(108, 92, 231, 0.25);
            border-radius: 50%;
            filter: blur(90px);
            top: 10%;
            left: 15%;
            z-index: 0;
        }

        body::after {
            content: '';
            position: absolute;
            width: 320px;
            height: 320px;
            background: rgba(0, 210, 211, 0.2);
            border-radius: 50%;
            filter: blur(80px);
            bottom: 10%;
            right: 15%;
            z-index: 0;
        }

        .login-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 1;
        }

        /* Brand Left Section */
        .brand-section {
            background: linear-gradient(145deg, rgba(42, 8, 69, 0.9) 0%, rgba(19, 15, 64, 0.95) 100%);
            padding: 40px 30px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }

        .brand-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #0984e3, #6c5ce7);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #fff;
            margin-bottom: 20px;
            box-shadow: 0 10px 20px rgba(108, 92, 231, 0.4);
        }

        .brand-title {
            font-size: 26px;
            font-weight: 700;
            letter-spacing: 0.5px;
            background: linear-gradient(90deg, #ffffff, #dfe6e9);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-subtitle {
            font-size: 13px;
            color: #a29bfe;
            margin-bottom: 25px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            font-size: 13px;
            color: #dfe6e9;
        }

        .feature-item i {
            color: var(--primary-accent);
            font-size: 15px;
        }

        /* Right Form Section */
        .login-section {
            padding: 45px 40px;
        }

        .login-title {
            font-size: 24px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 5px;
        }

        .login-subtitle {
            font-size: 13px;
            color: #b2bec3;
            margin-bottom: 25px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 500;
            color: #dfe6e9;
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            color: #a29bfe;
            font-size: 15px;
            z-index: 2;
        }

        .form-control {
            background-color: var(--input-bg) !important;
            border: 1px solid var(--input-border) !important;
            color: #ffffff !important;
            padding: 12px 45px 12px 45px;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            background-color: rgba(19, 15, 64, 0.95) !important;
            border-color: #6c5ce7 !important;
            box-shadow: 0 0 0 4px rgba(108, 92, 231, 0.25) !important;
            color: #fff !important;
        }

        .form-control::placeholder {
            color: rgba(223, 230, 233, 0.4);
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            background: none;
            border: none;
            color: #a29bfe;
            cursor: pointer;
            z-index: 2;
            padding: 0;
        }

        .toggle-password:hover {
            color: #ffffff;
        }

        .btn-login {
            background: var(--btn-gradient);
            border: none;
            color: #ffffff;
            padding: 13px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            letter-spacing: 0.5px;
            width: 100%;
            margin-top: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(108, 92, 231, 0.35);
        }

        .btn-login:hover {
            background: var(--btn-hover);
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(0, 206, 201, 0.4);
            color: #1e1248;
        }

        .login-footer {
            margin-top: 30px;
            text-align: center;
            color: #636e72;
            font-size: 12px;
        }

        .alert-danger {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid rgba(231, 76, 60, 0.4);
            color: #ff7675;
            border-radius: 10px;
            font-size: 13px;
        }

        .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100 py-4">
        <div class="col-12 col-md-10 col-lg-8 col-xl-7">
            <div class="login-card">
                <div class="row g-0">
                    <!-- Brand Banner Left -->
                    <div class="col-md-5 d-none d-md-block">
                        <div class="brand-section">
                            <div class="brand-icon">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <h2 class="brand-title">Fedly Cell</h2>
                            <p class="brand-subtitle">Point Of Sales & Multi Cabang</p>
                            
                            <div class="feature-item">
                                <i class="fas fa-check-circle"></i>
                                <span>Multi Cabang Terpadu</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-check-circle"></i>
                                <span>Top Up & Transfer Saldo</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-check-circle"></i>
                                <span>Mutasi Kas Real-time</span>
                            </div>
                        </div>
                    </div>

                    <!-- Form Login Right -->
                    <div class="col-md-7">
                        <div class="login-section">
                            <div class="d-md-none text-center mb-4">
                                <div class="brand-icon mx-auto" style="width: 55px; height: 55px; font-size: 24px;">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <h4 class="fw-bold text-white">Fedly Cell POS</h4>
                            </div>

                            <h3 class="login-title">Selamat Datang!</h3>
                            <p class="login-subtitle">Silakan login untuk mengelola transaksi</p>

                            <?php if($alertMessage): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?= htmlspecialchars($alertMessage) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                            <?php endif; ?>

                            <form id="loginForm" method="POST" action="">
                                <div class="form-group mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <div class="input-wrapper">
                                        <i class="fas fa-user input-icon"></i>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               placeholder="Masukkan username" 
                                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                                               required autocomplete="username" autofocus>
                                    </div>
                                </div>

                                <div class="form-group mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <div class="input-wrapper">
                                        <i class="fas fa-lock input-icon"></i>
                                        <input type="password" class="form-control" id="password" name="password" 
                                               placeholder="Masukkan password" 
                                               required autocomplete="current-password">
                                        <button type="button" class="toggle-password" id="togglePassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <button type="submit" class="btn-login" id="submitBtn">
                                    <span class="btn-text">Masuk ke Sistem <i class="fas fa-arrow-right ms-1"></i></span>
                                    <span class="btn-loader" style="display: none;"><i class="fas fa-spinner fa-spin me-1"></i> Memproses...</span>
                                </button>
                            </form>

                            <div class="login-footer">
                                <p class="mb-0">&copy; 2026 Fedly Cell POS System</p>
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
// Toggle password visibility
document.getElementById('togglePassword').addEventListener('click', function() {
    const pw = document.getElementById('password');
    const icon = this.querySelector('i');
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
    pw.type = pw.type === 'password' ? 'text' : 'password';
});

// Efek loading tombol submit
document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.querySelector('.btn-text').style.display = 'none';
    btn.querySelector('.btn-loader').style.display = 'inline-block';
});
</script>

</body>
</html>