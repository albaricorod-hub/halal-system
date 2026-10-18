<?php
require_once 'config.php';

$staffRegisterCsrf = csrfToken('staff_register_csrf');

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('staff_register_csrf');

    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';

    if (empty($full_name) || empty($email) || empty($password)) {
        $message = "All fields are required.";
        $message_type = "danger";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = "danger";
    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";
        $message_type = "danger";
    } else {
        try {
            $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $check->execute([$email]);
            if ($check->fetch()) {
                $message = "An account with this email already exists.";
                $message_type = "danger";
            } else {
                ensureSystemActivityTable($pdo);
                $pdo->beginTransaction();

                $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'staff', 'pending')");
                $stmt->execute([$full_name, $email, $hashed_pass]);
                $staffId = (int) $pdo->lastInsertId();

                $notif_text = "New staff registration: " . $full_name . " (" . $email . ") is awaiting approval.";
                notifyAdmins($pdo, $notif_text);
                recordSystemActivity($pdo, 'Staff account registered', 'A staff account was submitted for administrator approval.', $staffId, $full_name, 'staff');

                $pdo->commit();

                $message = "Registration successful! Your account is pending administrator approval.";
                $message_type = "success";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Staff registration failed: ' . $e->getMessage());
            $message = "Registration failed. Please try again.";
            $message_type = "danger";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/system-theme.css">
    <meta charset="UTF-8">
    <title>Staff Registration</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f9; display: flex; justify-content: center; padding-top: 50px; }
        .card { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .alert { padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        .alert-success { background: #d4edda; color: #155724; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="email"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #3498db; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
        button:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="card">
        <?php $brandTone = 'light'; include 'brand_logo.php'; ?>
        <h2>Staff Registration</h2>

        <?php if ($message): ?>
            <div class="alert alert-<?= h($message_type) ?>"><?= h($message) ?></div>
        <?php endif; ?>

        <form action="" method="POST">
            <input type="hidden" name="csrf_token" value="<?= h($staffRegisterCsrf) ?>">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit">Submit for Approval</button>
        </form>
    </div>
</body>
</html>