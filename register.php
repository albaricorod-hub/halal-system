<?php
require_once 'config.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('register_csrf');

    $name             = trim((string) ($_POST['name'] ?? ''));
    $email            = trim((string) ($_POST['email'] ?? ''));
    $contact_number   = trim((string) ($_POST['contact_number'] ?? ''));
    $gender           = trim((string) ($_POST['gender'] ?? ''));
    $address          = trim((string) ($_POST['address'] ?? ''));
    $password         = (string) ($_POST['password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');
    $role             = 'customer';

    if ($name === '' || $email === '' || $contact_number === '' || $gender === '' || $address === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (!preg_match('/^[0-9]{11}$/D', $contact_number)) {
        $error = 'Contact number must be exactly 11 digits.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
        $error = 'Please choose a valid gender option.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            try {
                ensureSystemActivityTable($pdo);
                $pdo->beginTransaction();
                $insert = $pdo->prepare('INSERT INTO users (name, email, contact_number, gender, address, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([$name, $email, $contact_number, $gender, $address, $hashed, $role, 'active']);
                $userId = (int) $pdo->lastInsertId();
                createNotification($pdo, $userId, 'Welcome to Halal Heritage', 'Your account is ready. Browse the menu and place your first order when you are signed in.');
                notifyAdmins($pdo, "New customer account registered: {$name}.");
                recordSystemActivity($pdo, 'Customer registered', "Customer account #{$userId} registered.", $userId, $name, 'customer');
                $pdo->commit();
                $success = 'Registration successful! You can now log in.';
                $_POST = [];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Registration failed: ' . $e->getMessage());
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}

$registerCsrf = csrfToken('register_csrf');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/system-theme.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Halal Heritage Archive</title>
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
            padding: 2rem 1.5rem;
        }
        .register-card {
            background: #fff;
            width: 100%; max-width: 1000px; min-height: 720px;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            display: flex; overflow: hidden;
        }
        .welcome-panel {
            flex: 0.85; position: relative; padding: 3rem 2.5rem;
            display: flex; flex-direction: column; justify-content: space-between;
            color: #fff; overflow: hidden;
        }
        .abstract-shape-1 { position: absolute; top: -10%; right: -10%; width: 260px; height: 260px; background: rgba(255,255,255,0.035); border-radius: 50%; pointer-events: none; }
        .abstract-shape-2 { position: absolute; bottom: -15%; left: -15%; width: 320px; height: 320px; background: rgba(255,255,255,0.045); border-radius: 50%; pointer-events: none; }
        .brand-header { position: relative; z-index: 2; flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.35rem; min-height: 0; text-align: center; }
        .brand-logo-img { max-height: min(50vh, 360px); max-width: 100%; width: auto; object-fit: contain; mix-blend-mode: screen; filter: brightness(0) invert(1) drop-shadow(0 8px 18px rgba(0,0,0,0.15)); }
        .brand-logo-text { font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 700; font-size: 1.8rem; color: #fff; text-align: center; text-shadow: 0 2px 4px rgba(0,0,0,0.15); }
        .welcome-content { position: relative; z-index: 2; max-width: 28rem; margin: 0; }
        .welcome-content h1 { font-family: 'Cormorant Garamond', Georgia, serif; font-size: 2.6rem; font-weight: 700; line-height: 1.05; margin-bottom: 1rem; }
        .welcome-content p { font-size: 0.95rem; color: rgba(255,255,255,0.85); line-height: 1.6; font-weight: 300; }
        .panel-footer { position: relative; z-index: 2; font-size: 0.8rem; color: rgba(255,255,255,0.6); margin-top: 1.5rem; }
        .form-panel { flex: 1.15; padding: 3rem 2.5rem; display: flex; flex-direction: column; justify-content: center; background-color: #fff; overflow-y: auto; max-height: 2000px; }
        .form-header { margin-bottom: 1.25rem; }
        .form-header h2 { font-size: 1.65rem; color: var(--text-dark); font-weight: 600; }
        .form-header p { color: var(--text-muted); font-size: 0.85rem; margin-top: 4px; }
        .error-alert { background-color: #fde8e8; color: #9b1c1c; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.25rem; border-left: 4px solid #f05252; }
        .success-alert { background-color: #def7ec; color: #03543f; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.25rem; border-left: 4px solid #31c48d; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.25rem 1rem; }
        .form-grid .full-width { grid-column: span 2; }
        .input-group { margin-bottom: 1rem; }
        .input-group label { display: block; font-size: 0.8rem; font-weight: 500; color: var(--text-dark); margin-bottom: 6px; margin-left: 12px; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon { position: absolute; left: 18px; width: 18px; height: 18px; stroke: var(--text-muted); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; pointer-events: none; }
        .pill-input, .pill-select { width: 100%; padding: 0.75rem 1rem 0.75rem 3rem; border-radius: 50px; border: 1.5px solid #E5E7EB; outline: none; font-size: 0.875rem; color: var(--text-dark); transition: all 0.2s ease-in-out; background-color: #FAFAFA; }
        .pill-input:focus, .pill-select:focus { border-color: var(--sage-medium); background-color: #fff; box-shadow: 0 0 0 4px rgba(88,129,87,0.15); }
        .btn-submit { width: 100%; padding: 0.85rem; border-radius: 50px; border: none; background-color: #1d4d42; color: #fff; font-size: 0.95rem; font-weight: 600; cursor: pointer; margin-top: 0.25rem; transition: background-color 0.2s, transform 0.1s; box-shadow: 0 4px 12px rgba(18,59,49,0.22); }
        .btn-submit:hover { background-color: #123b31; }
        .btn-submit:active { transform: scale(0.99); }
        .form-footer { margin-top: 1.25rem; text-align: center; font-size: 0.85rem; color: var(--text-muted); }
        .form-footer a { color: var(--sage-medium); font-weight: 600; text-decoration: none; }
        .form-footer a:hover { text-decoration: underline; }
        @media (max-width: 860px) {
            .register-card { flex-direction: column; max-width: 520px; }
            .welcome-panel, .form-panel { padding: 2.5rem 2rem; }
            .welcome-panel { min-height: 460px; }
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full-width { grid-column: span 1; }
        }
    </style>
</head>
<body>
<div class="register-card">
    <div class="welcome-panel patterned-welcome-panel">
        <div class="abstract-shape-1"></div>
        <div class="abstract-shape-2"></div>
        <div class="welcome-content">
            <h1 class="system-pattern-heading">Join Our Community</h1>
            <p>Register as a customer to discover authentic halal heritage dishes and place orders.</p>
        </div>
        <div class="brand-header">
            <div class="brand-logo-container"><img src="assets/logo.png" alt="Halal Heritage Emblem" class="brand-logo-img"></div>
            <div class="brand-logo-text">Halal Heritage</div>
        </div>
        <div class="panel-footer">&copy; <?= date('Y') ?> Halal Heritage Archive.</div>
    </div>

    <div class="form-panel">
        <div class="form-header">
            <h2 class="system-pattern-heading">Create Account</h2>
            <p>Fill in your registration details to get started</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="error-alert"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="success-alert">
                <?= h($success) ?> <a href="login.php" style="color: var(--sage-dark); font-weight: 700; text-decoration: underline;">Log in here</a>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= h($registerCsrf) ?>">

            <div class="form-grid">
                <div class="input-group full-width">
                    <label for="name">Full Name</label>
                    <div class="input-wrapper">
                        <input type="text" id="name" name="name" class="pill-input" placeholder="Enter your full name" value="<?= h($_POST['name'] ?? '') ?>" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </div>
                </div>

                <div class="input-group full-width">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" class="pill-input" placeholder="Email Address" value="<?= h($_POST['email'] ?? '') ?>" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </div>
                </div>

                <div class="input-group">
                    <label for="contact_number">Contact Number</label>
                    <div class="input-wrapper">
                        <input type="tel" inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" id="contact_number" name="contact_number" class="pill-input" placeholder="63912345678" value="<?= h($_POST['contact_number'] ?? '') ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)" title="Enter exactly 11 digits" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                    </div>
                </div>

                <div class="input-group">
                    <label for="gender">Gender</label>
                    <div class="input-wrapper">
                        <select id="gender" name="gender" class="pill-select" required>
                            <option value="" disabled <?= empty($_POST['gender']) ? 'selected' : '' ?>>Select gender</option>
                            <option value="Male"   <?= (($_POST['gender'] ?? '') === 'Male')   ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= (($_POST['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                            <option value="Other"  <?= (($_POST['gender'] ?? '') === 'Other')  ? 'selected' : '' ?>>Other</option>
                        </select>
                        <svg class="input-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>
                    </div>
                </div>

                <div class="input-group full-width">
                    <label for="address">Address</label>
                    <div class="input-wrapper">
                        <input type="text" id="address" name="address" class="pill-input" placeholder="Street, City, Province" value="<?= h($_POST['address'] ?? '') ?>" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                    </div>
                </div>

                <div class="input-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" class="pill-input" placeholder="At least 8 characters" minlength="8" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                </div>

                <div class="input-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" class="pill-input" placeholder="Repeat password" minlength="8" required>
                        <svg class="input-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit">Complete Registration</button>
            <div class="form-footer">Already registered? <a href="login.php">Sign In</a></div>
        </form>
    </div>
</div>
</body>
</html>