<?php
require_once 'config.php';

$error       = '';
$staffError  = '';
$adminError  = '';
$notice      = '';

$reasonMessages = [
    'invalid_session'    => 'Your session has expired. Please sign in again.',
    'staff_not_approved' => 'Your staff account is not approved. Contact an administrator.',
    'account_disabled'   => 'Your account has been disabled. Contact an administrator.',
];
$reason = $_GET['reason'] ?? '';
if (is_string($reason) && isset($reasonMessages[$reason])) {
    $notice = $reasonMessages[$reason];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $now = time();
    $_SESSION['login_attempts'] = array_values(array_filter(
        $_SESSION['login_attempts'] ?? [],
        static fn($t) => is_int($t) && ($now - $t) < 900
    ));

    if (count($_SESSION['login_attempts']) >= 10) {
        http_response_code(429);
        $error = 'Too many failed login attempts. Please wait 15 minutes and try again.';
    } else {
        requireCsrf('login_csrf');
        $loginType = $_POST['login_type'] ?? 'standard';

        if ($loginType === 'admin_passkey') {
            $adminEmail    = trim($_POST['admin_email'] ?? '');
            $adminPassword = $_POST['admin_password'] ?? '';

            if ($adminEmail === '' || $adminPassword === '') {
                $adminError = 'Please fill in all required fields.';
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND LOWER(role) = 'admin' LIMIT 1");
                $stmt->execute([$adminEmail]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($adminPassword, $admin['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $admin['id'];
                    $_SESSION['role']    = 'admin';
                    $_SESSION['name']    = $admin['name'];
                    $_SESSION['status']  = $admin['status'] ?? null;
                    unset($_SESSION['supplier_id'], $_SESSION['staff_login'], $_SESSION['login_attempts']);
                    header('Location: admin_dashboard.php');
                    exit;
                }
                $_SESSION['login_attempts'][] = $now;
                $adminError = 'Invalid admin credentials.';
            }
        } elseif ($loginType === 'staff') {
            $staffEmail    = trim($_POST['staff_email'] ?? '');
            $staffPassword = $_POST['staff_password'] ?? '';

            if ($staffEmail === '' || $staffPassword === '') {
                $staffError = 'Please fill in all required fields.';
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND LOWER(role) = 'staff' LIMIT 1");
                $stmt->execute([$staffEmail]);
                $staff = $stmt->fetch();

                if ($staff && password_verify($staffPassword, $staff['password'])) {
                    $status = strtolower(trim((string) ($staff['status'] ?? '')));
                    if ($status !== 'approved') {
                        $_SESSION['login_attempts'][] = $now;
                        $staffError = $status === 'pending'
                            ? 'Your staff account is awaiting administrator approval.'
                            : 'Your staff account is not active. Contact an administrator for help.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id']     = (int) $staff['id'];
                        $_SESSION['role']        = 'staff';
                        $_SESSION['name']        = $staff['name'];
                        $_SESSION['status']      = $staff['status'] ?? null;
                        $_SESSION['staff_login'] = true;
                        unset($_SESSION['supplier_id'], $_SESSION['login_attempts']);
                        header('Location: staff_dashboard.php');
                        exit;
                    }
                } else {
                    $_SESSION['login_attempts'][] = $now;
                    $staffError = 'Invalid staff credentials.';
                }
            }
        } else {
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $remember = isset($_POST['remember']);

            if ($email === '' || $password === '') {
                $error = 'Please fill in all required fields.';
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    $userRole   = strtolower(trim((string) ($user['role'] ?? '')));
                    $userStatus = strtolower(trim((string) ($user['status'] ?? '')));
                    $supplierId = null;
                    $blocked    = '';

                    if ($userRole === 'staff' && $userStatus !== 'approved') {
                        $blocked = 'Your account is pending administrator approval. You cannot log in yet.';
                    } elseif ($userRole === 'supplier') {
                        ensureHalalSupplierDirectoryColumns($pdo);
                        $supplierStmt = $pdo->prepare('SELECT id, verification_status FROM halal_suppliers WHERE supplier_user_id = ? LIMIT 1');
                        $supplierStmt->execute([(int) $user['id']]);
                        $supplierRow = $supplierStmt->fetch();
                        if (!$supplierRow) {
                            $blocked = 'Your supplier account is not linked to a supplier profile yet. Contact an administrator.';
                        } elseif (strtolower((string) ($supplierRow['verification_status'] ?? '')) !== 'verified') {
                            $blocked = 'Your supplier profile is not yet verified. Contact an administrator.';
                        } else {
                            $supplierId = (int) $supplierRow['id'];
                        }
                    } elseif (in_array($userStatus, ['inactive', 'disabled', 'banned', 'suspended'], true)) {
                        $blocked = 'Your account has been disabled. Contact an administrator.';
                    }

                    if ($blocked !== '') {
                        $_SESSION['login_attempts'][] = $now;
                        $error = $blocked;
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int) $user['id'];
                        $_SESSION['role']    = $userRole;
                        $_SESSION['name']    = $user['name'];
                        $_SESSION['status']  = $user['status'] ?? null;
                        unset($_SESSION['staff_login'], $_SESSION['login_attempts']);
                        if ($supplierId !== null) {
                            $_SESSION['supplier_id'] = $supplierId;
                        } else {
                            unset($_SESSION['supplier_id']);
                        }

                        if ($remember) {
                            setcookie('user_email', $email, [
                                'expires'  => time() + (86400 * 30),
                                'path'     => '/',
                                'secure'   => !empty($_SERVER['HTTPS']),
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ]);
                        } else {
                            setcookie('user_email', '', time() - 3600, '/');
                        }

                        if ($userRole === 'admin') {
                            header('Location: admin_dashboard.php');
                        } elseif ($userRole === 'staff') {
                            header('Location: staff_dashboard.php');
                        } elseif ($userRole === 'supplier') {
                            header('Location: suppliers_dashboard.php');
                        } else {
                            header('Location: index.php');
                        }
                        exit;
                    }
                } else {
                    $_SESSION['login_attempts'][] = $now;
                    $error = 'Invalid email or password.';
                }
            }
        }
    }
}

$loginCsrf  = csrfToken('login_csrf');
$savedEmail = is_string($_COOKIE['user_email'] ?? null) ? $_COOKIE['user_email'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/system-theme.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Halal Heritage Archive</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-lavender: #E8E5F0;
            --sage-dark: #3A5A40;
            --sage-medium: #588157;
            --sage-light: #A3B18A;
            --sage-soft: #DAD7CD;
            --text-dark: #2F3E46;
            --text-muted: #6C757D;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
        body {
            background-color: var(--bg-lavender);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
        }
        .login-card {
            background: #ffffff;
            width: 100%; max-width: 960px; min-height: 600px;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            display: flex; overflow: hidden;
        }
        .welcome-panel {
            flex: 1; position: relative; padding: 3rem 2.5rem;
            display: flex; flex-direction: column; justify-content: space-between;
            color: #fff; overflow: hidden;
        }
        .abstract-shape-1 {
            position: absolute; top: -10%; right: -10%;
            width: 260px; height: 260px;
            background: rgba(255,255,255,0.035); border-radius: 50%; pointer-events: none;
        }
        .abstract-shape-2 {
            position: absolute; bottom: -15%; left: -15%;
            width: 320px; height: 320px;
            background: rgba(255,255,255,0.045); border-radius: 50%; pointer-events: none;
        }
        .brand-header {
            position: relative; z-index: 2; flex: 1;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 0.35rem; min-height: 0; text-align: center;
        }
        .brand-logo-img {
            max-height: min(50vh, 360px); max-width: 100%; width: auto;
            object-fit: contain; mix-blend-mode: screen;
            filter: brightness(0) invert(1) drop-shadow(0 8px 18px rgba(0,0,0,0.15));
        }
        .brand-logo-text {
            font-family: 'Cormorant Garamond', Georgia, serif;
            font-weight: 700; font-size: 1.8rem; color: #fff;
            text-align: center; text-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }
        .welcome-content { position: relative; z-index: 2; max-width: 28rem; margin: 0; }
        .welcome-content h1 {
            font-family: 'Cormorant Garamond', Georgia, serif;
            font-size: 2.6rem; font-weight: 700; line-height: 1.05; margin-bottom: 1rem;
        }
        .welcome-content p { font-size: 0.95rem; color: rgba(255,255,255,0.85); line-height: 1.6; font-weight: 300; }
        .panel-footer { position: relative; z-index: 2; font-size: 0.8rem; color: rgba(255,255,255,0.6); margin-top: 1.5rem; }
        .form-panel {
            flex: 1; padding: 3rem 2.5rem;
            display: flex; flex-direction: column; justify-content: center;
            background-color: #fff;
        }
        .tabs {
            display: flex; background: #F3F4F6; padding: 4px;
            border-radius: 50px; margin-bottom: 1.5rem;
        }
        .tab-btn {
            flex: 1; border: none; background: transparent;
            padding: 8px 12px; font-size: 0.825rem; font-weight: 600;
            color: var(--text-muted); border-radius: 50px; cursor: pointer;
            transition: all 0.2s ease;
        }
        .tab-btn.active { background: #fff; color: var(--sage-dark); box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
        .form-header { margin-bottom: 1.5rem; }
        .form-header h2 { font-size: 1.65rem; color: var(--text-dark); font-weight: 600; }
        .form-header p { color: var(--text-muted); font-size: 0.85rem; margin-top: 4px; }
        .error-alert {
            background-color: #fde8e8; color: #9b1c1c;
            padding: 0.75rem 1rem; border-radius: 12px;
            font-size: 0.85rem; margin-bottom: 1.25rem;
            border-left: 4px solid #f05252;
        }
        .notice-alert {
            background-color: #eef5f1; color: #163b32;
            padding: 0.75rem 1rem; border-radius: 12px;
            font-size: 0.85rem; margin-bottom: 1.25rem;
            border-left: 4px solid #588157;
        }
        .input-group { margin-bottom: 1.25rem; }
        .input-group label {
            display: block; font-size: 0.8rem; font-weight: 500;
            color: var(--text-dark); margin-bottom: 6px; margin-left: 12px;
        }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon {
            position: absolute; left: 18px; width: 18px; height: 18px;
            stroke: var(--text-muted); fill: none; stroke-width: 2;
            stroke-linecap: round; stroke-linejoin: round; pointer-events: none;
        }
        .pill-input {
            width: 100%; padding: 0.85rem 1rem 0.85rem 3rem;
            border-radius: 50px; border: 1.5px solid #E5E7EB; outline: none;
            font-size: 0.9rem; color: var(--text-dark);
            transition: all 0.2s ease-in-out; background-color: #FAFAFA;
        }
        .pill-input:focus {
            border-color: var(--sage-medium); background-color: #fff;
            box-shadow: 0 0 0 4px rgba(88,129,87,0.15);
        }
        .form-options {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem; padding: 0 8px; font-size: 0.825rem;
        }
        .remember-me { display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--text-muted); }
        .remember-me input[type="checkbox"] { accent-color: var(--sage-medium); width: 16px; height: 16px; cursor: pointer; }
        .forgot-link { color: var(--sage-medium); text-decoration: none; font-weight: 500; }
        .forgot-link:hover { text-decoration: underline; }
        .btn-submit {
            width: 100%; padding: 0.9rem; border-radius: 50px; border: none;
            background-color: #1d4d42; color: #fff; font-size: 0.95rem; font-weight: 600;
            cursor: pointer; transition: background-color 0.2s, transform 0.1s;
            box-shadow: 0 4px 12px rgba(18,59,49,0.22);
        }
        .btn-submit:hover { background-color: #123b31; }
        .btn-submit:active { transform: scale(0.99); }
        .form-footer { margin-top: 1.75rem; text-align: center; font-size: 0.85rem; color: var(--text-muted); }
        .form-footer a { color: var(--sage-medium); font-weight: 600; text-decoration: none; }
        .form-footer a:hover { text-decoration: underline; }
        .login-form-section { display: none; }
        .login-form-section.active { display: block; }
        @media (max-width: 820px) {
            .login-card { flex-direction: column; max-width: 480px; }
            .welcome-panel, .form-panel { padding: 2.5rem 2rem; }
            .welcome-panel { min-height: 460px; }
            .brand-logo-img { max-height: min(40vh, 300px); }
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="welcome-panel patterned-welcome-panel">
        <div class="abstract-shape-1"></div>
        <div class="abstract-shape-2"></div>
        <div class="welcome-content">
            <h1 id="panel-heading" class="system-pattern-heading">Welcome Back</h1>
            <p id="panel-subtext">Discover traditional recipes, deep cultural histories, and verified authentic origins.</p>
        </div>
        <div class="brand-header">
            <div class="brand-logo-container">
                <img src="assets/logo.png" alt="Halal Heritage Emblem" class="brand-logo-img">
            </div>
            <div class="brand-logo-text">Halal Heritage</div>
        </div>
        <div class="panel-footer">&copy; <?= date('Y') ?> Halal Heritage Archive.</div>
    </div>

    <div class="form-panel">
        <?php if ($notice !== ''): ?>
            <div class="notice-alert"><?= h($notice) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <button type="button" class="tab-btn <?= ($staffError === '' && $adminError === '') ? 'active' : '' ?>" onclick="switchTab('user')">User Login</button>
            <button type="button" class="tab-btn <?= ($staffError !== '') ? 'active' : '' ?>" onclick="switchTab('staff')">Staff Login</button>
            <button type="button" class="tab-btn <?= ($adminError !== '') ? 'active' : '' ?>" onclick="switchTab('admin')">Admin Passkey</button>
        </div>

        <div id="user-form-section" class="login-form-section <?= ($staffError === '' && $adminError === '') ? 'active' : '' ?>">
            <div class="form-header">
                <h2 class="system-pattern-heading">Sign In</h2>
                <p>Enter your account credentials to continue</p>
            </div>
            <?php if ($error !== ''): ?>
                <div class="error-alert"><?= h($error) ?></div>
            <?php endif; ?>
            <form action="login.php" method="POST" autocomplete="on">
                <input type="hidden" name="login_type" value="standard">
                <input type="hidden" name="csrf_token" value="<?= h($loginCsrf) ?>">
                <div class="input-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" class="pill-input" placeholder="Email" value="<?= h($savedEmail) ?>" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </div>
                </div>
                <div class="input-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" class="pill-input" placeholder="••••••••" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                </div>
                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" <?= $savedEmail !== '' ? 'checked' : '' ?>>
                        <span>Remember me</span>
                    </label>
                    <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
                </div>
                <button type="submit" class="btn-submit">Sign In</button>
                <div class="form-footer">Don't have an account? <a href="register.php">Create Account</a></div>
            </form>
        </div>

        <div id="staff-form-section" class="login-form-section <?= ($staffError !== '') ? 'active' : '' ?>">
            <div class="form-header">
                <h2 class="system-pattern-heading">Staff Workspace</h2>
                <p>Use your staff account credentials to continue</p>
            </div>
            <?php if ($staffError !== ''): ?>
                <div class="error-alert"><?= h($staffError) ?></div>
            <?php endif; ?>
            <form action="login.php" method="POST">
                <input type="hidden" name="login_type" value="staff">
                <input type="hidden" name="csrf_token" value="<?= h($loginCsrf) ?>">
                <div class="input-group">
                    <label for="staff_email">Staff Email Address</label>
                    <div class="input-wrapper">
                        <input type="email" id="staff_email" name="staff_email" class="pill-input" placeholder="Staff Email" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </div>
                </div>
                <div class="input-group">
                    <label for="staff_password">Staff Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="staff_password" name="staff_password" class="pill-input" placeholder="Password" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                </div>
                <button type="submit" class="btn-submit" style="margin-top: 1rem;">Open Staff Dashboard</button>
                <div class="form-footer">Switch back to <a href="javascript:void(0)" onclick="switchTab('user')">User Login</a></div>
            </form>
        </div>

        <div id="admin-form-section" class="login-form-section <?= ($adminError !== '') ? 'active' : '' ?>">
            <div class="form-header">
                <h2 class="system-pattern-heading">Admin Portal</h2>
                <p>Enter your administrative credentials to continue</p>
            </div>
            <?php if ($adminError !== ''): ?>
                <div class="error-alert"><?= h($adminError) ?></div>
            <?php endif; ?>
            <form action="login.php" method="POST">
                <input type="hidden" name="login_type" value="admin_passkey">
                <input type="hidden" name="csrf_token" value="<?= h($loginCsrf) ?>">
                <div class="input-group">
                    <label for="admin_email">Admin Email Address</label>
                    <div class="input-wrapper">
                        <input type="email" id="admin_email" name="admin_email" class="pill-input" placeholder="Admin Email" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </div>
                </div>
                <div class="input-group">
                    <label for="admin_password">Admin Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="admin_password" name="admin_password" class="pill-input" placeholder="Password" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                </div>
                <button type="submit" class="btn-submit" style="margin-top: 1rem;">Access Dashboard</button>
                <div class="form-footer">Switch back to <a href="javascript:void(0)" onclick="switchTab('user')">User Login</a></div>
            </form>
        </div>
    </div>
</div>

<script>
    function switchTab(type) {
        const userSection  = document.getElementById('user-form-section');
        const staffSection = document.getElementById('staff-form-section');
        const adminSection = document.getElementById('admin-form-section');
        const tabBtns      = document.querySelectorAll('.tab-btn');
        const heading      = document.getElementById('panel-heading');
        const subtext      = document.getElementById('panel-subtext');

        if (type === 'admin') {
            userSection.classList.remove('active');
            staffSection.classList.remove('active');
            adminSection.classList.add('active');
            tabBtns[0].classList.remove('active');
            tabBtns[1].classList.remove('active');
            tabBtns[2].classList.add('active');
            heading.innerText = "System Admin";
            subtext.innerText = "Access system settings, manage registered foods, user roles, and order records.";
        } else if (type === 'staff') {
            userSection.classList.remove('active');
            staffSection.classList.add('active');
            adminSection.classList.remove('active');
            tabBtns[0].classList.remove('active');
            tabBtns[1].classList.add('active');
            tabBtns[2].classList.remove('active');
            heading.innerText = "Staff Workspace";
            subtext.innerText = "Process customer orders, update order status, and manage daily operations.";
        } else {
            staffSection.classList.remove('active');
            adminSection.classList.remove('active');
            userSection.classList.add('active');
            tabBtns[0].classList.add('active');
            tabBtns[1].classList.remove('active');
            tabBtns[2].classList.remove('active');
            heading.innerText = "Welcome Back";
            subtext.innerText = "Discover traditional recipes, deep cultural histories, and verified authentic origins.";
        }
    }
</script>
</body>
</html>