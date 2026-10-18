<?php
require_once 'config.php';
checkAuth();
ensureUserProfileImageColumn($pdo);

$userId     = (int) ($_SESSION['user_id'] ?? 0);
$isCustomer = strtolower(trim((string) ($_SESSION['role'] ?? ''))) === 'customer';
if ($isCustomer) {
    ensureCustomerLoyaltyColumns($pdo);
}

$success = '';
$error   = '';

$stmt = $pdo->prepare('SELECT id, name, email, role, created_at, profile_image, password FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: logout.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    requireCsrf('profile_csrf');

    $name              = trim((string) ($_POST['name'] ?? ''));
    $email             = trim((string) ($_POST['email'] ?? ''));
    $current_password  = (string) ($_POST['current_password'] ?? '');
    $new_password      = (string) ($_POST['new_password'] ?? '');
    $confirm_password  = (string) ($_POST['confirm_password'] ?? '');
    $profileImagePath  = $user['profile_image'] ?? null;
    $emailChanged      = strcasecmp($email, (string) $user['email']) !== 0;
    $passwordChanged   = $new_password !== '';

    if ($name === '' || $email === '') {
        $error = 'Name and Email address are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a valid email address.';
    } elseif (($emailChanged || $passwordChanged) && $current_password === '') {
        $error = 'Enter your current password to change your email or password.';
    } elseif (($emailChanged || $passwordChanged) && !password_verify($current_password, (string) $user['password'])) {
        $error = 'Your current password is incorrect.';
    } elseif ($passwordChanged && strlen($new_password) < 8) {
        $error = 'New password must be at least 8 characters long.';
    } elseif ($passwordChanged && $new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    }

    if ($error === '' && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadFile = $_FILES['profile_image'];
        if ($uploadFile['error'] !== UPLOAD_ERR_OK) {
            $error = 'There was a problem uploading your profile picture.';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = $finfo ? finfo_file($finfo, $uploadFile['tmp_name']) : false;
            if ($finfo) {
                finfo_close($finfo);
            }
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

            if (!is_string($mimeType) || !isset($allowed[$mimeType])) {
                $error = 'Please upload a JPG, PNG, or WEBP profile picture.';
            } elseif ((int) $uploadFile['size'] > 2 * 1024 * 1024) {
                $error = 'Profile picture must be 2MB or smaller.';
            } else {
                $uploadDir = __DIR__ . '/uploads/profile_pictures';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
                    $error = 'Unable to save your profile picture. Please try again.';
                } else {
                    $fileName = 'user_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mimeType];
                    $destination = $uploadDir . '/' . $fileName;

                    if (!move_uploaded_file($uploadFile['tmp_name'], $destination)) {
                        $error = 'Your profile picture could not be saved.';
                    } else {
                        $newProfilePath = 'uploads/profile_pictures/' . $fileName;
                        $oldImagePath   = trim((string) ($user['profile_image'] ?? ''));
                        if ($oldImagePath !== '' && $oldImagePath !== $newProfilePath) {
                            $uploadsRoot   = realpath(__DIR__ . '/uploads/profile_pictures');
                            $oldImageReal  = realpath(__DIR__ . '/' . ltrim($oldImagePath, '/'));
                            if ($uploadsRoot && $oldImageReal && strpos($oldImageReal, $uploadsRoot) === 0 && is_file($oldImageReal)) {
                                @unlink($oldImageReal);
                            }
                        }
                        $profileImagePath = $newProfilePath;
                    }
                }
            }
        }
    }

    if ($error === '') {
        if ($emailChanged) {
            $checkEmail = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? LIMIT 1');
            $checkEmail->execute([$email, $userId]);
            if ($checkEmail->fetch()) {
                $error = 'This email is already in use by another account.';
            }
        }
    }

    if ($error === '') {
        $params    = [$name, $email];
        $setClause = 'name = ?, email = ?';

        if ($profileImagePath !== null && $profileImagePath !== ($user['profile_image'] ?? null)) {
            $setClause .= ', profile_image = ?';
            $params[] = $profileImagePath;
        }
        if ($passwordChanged) {
            $setClause .= ', password = ?';
            $params[] = password_hash($new_password, PASSWORD_BCRYPT);
        }
        $params[] = $userId;

        try {
            $updateStmt = $pdo->prepare("UPDATE users SET {$setClause} WHERE id = ?");
            $updateStmt->execute($params);

            $_SESSION['name'] = $name;
            $_SESSION['profile_image'] = $profileImagePath;
            $user['name']          = $name;
            $user['email']         = $email;
            $user['profile_image'] = $profileImagePath;

            $success = $passwordChanged
                ? 'Profile details and password updated successfully!'
                : 'Profile details updated successfully!';
        } catch (Throwable $e) {
            error_log('Profile update failed: ' . $e->getMessage());
            $error = 'Your profile could not be updated. Please try again.';
        }
    }
}

$availableLoyaltyRewards = [];
$loyaltyStatus = null;
if (strtolower(trim((string) $user['role'])) === 'customer') {
    try {
        $loyaltyStatus = refreshCustomerLoyalty($pdo, $userId);
        $user['loyalty_points'] = $loyaltyStatus['points'];
        $user['loyalty_level']  = $loyaltyStatus['level'];
        $user['total_spent']    = $loyaltyStatus['total_spent'];
        $rewardStmt = $pdo->prepare("SELECT tier, food_name FROM customer_loyalty_rewards WHERE user_id = ? AND status = 'pending' ORDER BY id");
        $rewardStmt->execute([$userId]);
        $availableLoyaltyRewards = $rewardStmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Loyalty refresh failed: ' . $e->getMessage());
        $loyaltyStatus = [
            'level' => 'Bronze', 'points' => 0, 'total_spent' => 0.0,
            'next_level' => 'Silver', 'next_threshold' => 500,
            'points_needed' => 500, 'progress' => 0,
        ];
        $user['loyalty_points'] = 0;
        $user['loyalty_level']  = 'Bronze';
        $user['total_spent']    = 0.0;
    }
}

$profileCsrf = csrfToken('profile_csrf');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen flex flex-col font-sans">

    <?php include 'navbar.php'; ?>

    <main class="max-w-3xl mx-auto px-4 py-8 flex-grow w-full">
        <div class="mb-6 flex justify-between items-center border-b border-stone-300 pb-4">
            <div>
                <h1 class="text-3xl font-extrabold text-emerald-950 flex items-center gap-2.5">
                    <svg class="w-8 h-8 text-emerald-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <span>My Profile</span>
                </h1>
                <p class="text-stone-600 text-sm mt-1">Manage and update your personal account details.</p>
            </div>
            <a href="order.php" class="bg-amber-500 hover:bg-amber-400 text-emerald-950 px-4 py-2.5 rounded-xl text-xs font-bold transition shadow-sm border border-amber-600 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                <span>Track Orders</span>
            </a>
        </div>

        <?php if ($success !== ''): ?>
            <div class="mb-6 p-4 bg-emerald-100 text-emerald-900 border border-emerald-300 rounded-xl flex items-center gap-2 text-sm font-semibold shadow-sm" role="status">
                <svg class="w-5 h-5 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><?= h($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="mb-6 p-4 bg-rose-100 text-rose-900 border border-rose-300 rounded-xl flex items-center gap-2 text-sm font-semibold shadow-sm" role="alert">
                <svg class="w-5 h-5 text-rose-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><?= h($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
            <div class="p-6 bg-emerald-950 text-white flex justify-between items-center border-b border-emerald-900">
                <div class="flex items-center gap-3">
                    <?php if (!empty($user['profile_image'])): ?>
                        <img src="<?= h($user['profile_image']) ?>" alt="<?= h($user['name']) ?> profile picture" class="w-12 h-12 rounded-full object-cover border-2 border-amber-400 shadow">
                    <?php else: ?>
                        <div class="w-12 h-12 bg-amber-500 text-emerald-950 rounded-full font-extrabold flex items-center justify-center text-xl shadow">
                            <?= h(strtoupper(substr($user['name'], 0, 1))) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h2 class="text-lg font-bold text-amber-400"><?= h($user['name']) ?></h2>
                        <p class="text-xs text-stone-300">Account Role: <span class="capitalize font-semibold text-emerald-300"><?= h($user['role']) ?></span></p>
                    </div>
                </div>
                <span class="text-xs text-stone-400 bg-emerald-900/80 px-3 py-1 rounded-full border border-emerald-800">Member since <?= date('M Y', strtotime($user['created_at'])) ?></span>
            </div>

            <!-- enctype removed from markup; toggled via JS when a file is selected -->
            <form action="profile.php" method="POST" id="profile-form" class="p-6 space-y-5">
                <input type="hidden" name="csrf_token" value="<?= h($profileCsrf) ?>">
                <input type="hidden" name="update_profile" value="1">

                <div class="flex flex-col sm:flex-row items-center gap-4 rounded-2xl border border-stone-200 bg-stone-50 p-4">
                    <?php if (!empty($user['profile_image'])): ?>
                        <img src="<?= h($user['profile_image']) ?>" alt="Current profile picture" class="w-20 h-20 rounded-full object-cover border-2 border-emerald-700 shadow-sm">
                    <?php else: ?>
                        <div class="w-20 h-20 bg-emerald-100 text-emerald-900 rounded-full font-extrabold flex items-center justify-center text-2xl border-2 border-emerald-700">
                            <?= h(strtoupper(substr($user['name'], 0, 1))) ?>
                        </div>
                    <?php endif; ?>
                    <div class="w-full">
                        <label for="profile_image" class="block text-xs font-bold text-stone-700 uppercase tracking-wider mb-1.5">Profile Picture</label>
                        <input type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/webp"
                               class="block w-full text-sm text-stone-600 file:mr-3 file:rounded-xl file:border-0 file:bg-emerald-800 file:px-4 file:py-2 file:text-sm file:font-bold file:text-white hover:file:bg-emerald-700">
                        <p class="mt-2 text-[11px] text-stone-500">Upload a JPG, PNG, or WEBP image up to 2MB.</p>
                    </div>
                </div>

                <?php if ($isCustomer && $loyaltyStatus): ?>
                    <div class="rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 to-emerald-50 p-4">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-amber-700">Loyalty Tracker</p>
                                <h3 class="mt-1 text-xl font-extrabold text-emerald-950"><?= h($user['loyalty_level'] ?? 'Bronze') ?> Member</h3>
                            </div>
                            <div class="inline-flex items-center rounded-full border border-emerald-200 bg-white px-3 py-1 text-xs font-bold text-emerald-900">
                                <?= (int) ($user['loyalty_points'] ?? 0) ?> points
                            </div>
                        </div>
                        <div class="mt-4">
                            <div class="flex items-center justify-between text-[11px] font-bold uppercase tracking-wider text-stone-600">
                                <span>Progress</span>
                                <span><?= (int) $loyaltyStatus['progress'] ?>%</span>
                            </div>
                            <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-stone-200">
                                <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-emerald-600" style="width: <?= (int) $loyaltyStatus['progress'] ?>%"></div>
                            </div>
                            <div class="mt-3 flex items-center justify-between text-xs text-stone-600">
                                <span>Total spent: ₱<?= number_format((float) ($user['total_spent'] ?? 0), 2) ?></span>
                                <?php if (!empty($loyaltyStatus['next_level'])): ?>
                                    <span><?= (int) $loyaltyStatus['points_needed'] ?> pts to <?= h($loyaltyStatus['next_level']) ?></span>
                                <?php else: ?>
                                    <span>Maximum loyalty tier reached</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($availableLoyaltyRewards)): ?>
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                            <h3 class="text-sm font-bold text-emerald-950">Free food rewards ready</h3>
                            <p class="mt-1 text-xs text-stone-600">These rewards are automatically added free to your next order.</p>
                            <ul class="mt-3 space-y-2">
                                <?php foreach ($availableLoyaltyRewards as $reward): ?>
                                    <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <span class="font-semibold text-stone-800"><?= h($reward['food_name']) ?></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-[10px] font-bold uppercase text-emerald-800"><?= h($reward['tier']) ?> reward</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div>
                    <label for="name" class="block text-xs font-bold text-stone-700 uppercase tracking-wider mb-1.5">Full Name</label>
                    <input type="text" id="name" name="name" value="<?= h($user['name']) ?>" required
                           class="w-full border border-stone-300 rounded-xl p-3 text-sm focus:ring-2 focus:ring-emerald-700 focus:outline-none bg-stone-50">
                </div>

                <div>
                    <label for="email" class="block text-xs font-bold text-stone-700 uppercase tracking-wider mb-1.5">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= h($user['email']) ?>" required
                           class="w-full border border-stone-300 rounded-xl p-3 text-sm focus:ring-2 focus:ring-emerald-700 focus:outline-none bg-stone-50">
                </div>

                <hr class="border-stone-200 my-4">

                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <label for="current_password" class="block text-xs font-bold text-amber-900 uppercase tracking-wider mb-1.5">Current Password</label>
                    <input type="password" id="current_password" name="current_password" placeholder="Required to change your email or password"
                           class="w-full border border-amber-300 rounded-xl p-3 text-sm focus:ring-2 focus:ring-amber-500 focus:outline-none bg-white" autocomplete="current-password">
                    <p class="mt-1 text-[11px] text-amber-800">You only need to enter this when changing your email or password.</p>
                </div>

                <div>
                    <h3 class="text-sm font-bold text-emerald-950 mb-1">Change Password</h3>
                    <p class="text-xs text-stone-500 mb-3">Leave these fields blank if you do not wish to update your password.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="new_password" class="block text-xs font-bold text-stone-700 uppercase tracking-wider mb-1.5">New Password</label>
                        <input type="password" id="new_password" name="new_password" placeholder="At least 8 characters" minlength="8"
                               class="w-full border border-stone-300 rounded-xl p-3 text-sm focus:ring-2 focus:ring-emerald-700 focus:outline-none bg-stone-50" autocomplete="new-password">
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-xs font-bold text-stone-700 uppercase tracking-wider mb-1.5">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat new password" minlength="8"
                               class="w-full border border-stone-300 rounded-xl p-3 text-sm focus:ring-2 focus:ring-emerald-700 focus:outline-none bg-stone-50" autocomplete="new-password">
                    </div>
                </div>

                <div class="pt-4 flex justify-end">
                    <button type="submit" class="w-full sm:w-auto bg-emerald-800 hover:bg-emerald-700 text-white font-bold px-6 py-3 rounded-xl transition shadow-sm text-sm border border-emerald-900 flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span>Save Profile Changes</span>
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script>
        (function () {
            const form = document.getElementById('profile-form');
            const fileInput = document.getElementById('profile_image');
            if (!form || !fileInput) return;
            const sync = () => {
                if (fileInput.files && fileInput.files.length > 0) {
                    form.setAttribute('enctype', 'multipart/form-data');
                } else {
                    form.removeAttribute('enctype');
                }
            };
            fileInput.addEventListener('change', sync);
            sync();
        })();
    </script>
</body>
</html>