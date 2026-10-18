<?php
require_once 'config.php';
checkAuth(['customer']);

$userId     = (int) $_SESSION['user_id'];
$formCsrf   = csrfToken('customer_form_csrf');
$error      = '';
$success    = '';

/* ------------------------------------------------------------------ */
/* Load current user                                                  */
/* ------------------------------------------------------------------ */
$stmt = $pdo->prepare('SELECT id, name, email, contact_number, gender, address, role, status, created_at FROM users WHERE id = ? AND LOWER(role) = \'customer\' LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    // Account role changed mid-session; force re-login
    header('Location: logout.php');
    exit;
}

/* ------------------------------------------------------------------ */
/* Handle submission                                                  */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_customer_info'])) {
    requireCsrf('customer_form_csrf');

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $phone    = trim((string) ($_POST['phone'] ?? ''));
    $gender   = trim((string) ($_POST['gender'] ?? ''));
    $address  = trim((string) ($_POST['address'] ?? ''));

    if ($fullName === '' || $email === '' || $phone === '' || $gender === '' || $address === '') {
        $error = 'Please fill out all required fields.';
    } elseif (!preg_match('/^[0-9]{11}$/D', $phone)) {
        $error = 'Phone number must be exactly 11 digits.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a valid email address.';
    } elseif (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
        $error = 'Please choose a valid gender option.';
    } else {
        // Check email uniqueness
        $dup = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
        $dup->execute([$email, $userId]);
        if ($dup->fetch()) {
            $error = 'That email address is already used by another account.';
        }
    }

    if ($error === '') {
        try {
            $pdo->prepare(
                'UPDATE users SET name = ?, email = ?, contact_number = ?, gender = ?, address = ? WHERE id = ? AND LOWER(role) = \'customer\''
            )->execute([$fullName, $email, $phone, $gender, $address, $userId]);

            $_SESSION['name'] = $fullName;

            // Refresh local copy for display
            $user['name']           = $fullName;
            $user['email']          = $email;
            $user['contact_number'] = $phone;
            $user['gender']         = $gender;
            $user['address']        = $address;

            $success = 'Your details have been saved.';
        } catch (Throwable $e) {
            error_log('customer_form.php save failed: ' . $e->getMessage());
            $error = 'Unable to save your details. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Details Form - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/system-theme.css">
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen flex flex-col font-sans">
    <?php include 'navbar.php'; ?>

    <main class="max-w-2xl mx-auto px-4 py-8 flex-grow w-full">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3 border-b border-stone-300 pb-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Customer profile</p>
                <h1 class="mt-2 text-2xl sm:text-3xl font-extrabold text-emerald-950">Personal Information</h1>
                <p class="text-sm text-stone-600 mt-1">Keep your contact details and delivery address up to date.</p>
            </div>
            <a href="profile.php" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-bold text-stone-700 hover:bg-stone-50">
                Account &amp; password settings &rarr;
            </a>
        </div>

        <?php if ($error !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900"><?= h($success) ?></div>
        <?php endif; ?>

        <form method="POST" class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6 sm:p-8 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= h($formCsrf) ?>">
            <input type="hidden" name="save_customer_info" value="1">

            <div>
                <label for="full_name" class="block text-sm font-bold text-emerald-950 mb-1">Full Name <span class="text-red-600">*</span></label>
                <input type="text" id="full_name" name="full_name" required maxlength="100"
                       value="<?= h($user['name']) ?>"
                       class="w-full rounded-xl border border-stone-300 px-4 py-3 bg-stone-50 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="email" class="block text-sm font-bold text-emerald-950 mb-1">Email Address <span class="text-red-600">*</span></label>
                    <input type="email" id="email" name="email" required maxlength="100"
                           value="<?= h($user['email']) ?>"
                           class="w-full rounded-xl border border-stone-300 px-4 py-3 bg-stone-50 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                </div>

                <div>
                    <label for="phone" class="block text-sm font-bold text-emerald-950 mb-1">Phone Number <span class="text-red-600">*</span></label>
                    <input type="tel" inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11"
                           id="phone" name="phone" required
                           value="<?= h($user['contact_number'] ?? '') ?>"
                           placeholder="09XXXXXXXXX"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)"
                           title="Enter exactly 11 digits"
                           class="w-full rounded-xl border border-stone-300 px-4 py-3 bg-stone-50 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                </div>
            </div>

            <div>
                <label for="gender" class="block text-sm font-bold text-emerald-950 mb-1">Gender <span class="text-red-600">*</span></label>
                <select id="gender" name="gender" required
                        class="w-full rounded-xl border border-stone-300 px-4 py-3 bg-white focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                    <option value="" disabled <?= empty($user['gender']) ? 'selected' : '' ?>>Select gender</option>
                    <option value="Male"   <?= ($user['gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= ($user['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                    <option value="Other"  <?= ($user['gender'] ?? '') === 'Other'  ? 'selected' : '' ?>>Other</option>
                </select>
            </div>

            <div>
                <label for="address" class="block text-sm font-bold text-emerald-950 mb-1">Delivery Address <span class="text-red-600">*</span></label>
                <textarea id="address" name="address" rows="3" required maxlength="500"
                          placeholder="Street, Barangay, City, Province, ZIP"
                          class="w-full rounded-xl border border-stone-300 px-4 py-3 bg-stone-50 focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100"><?= h($user['address'] ?? '') ?></textarea>
                <p class="mt-1 text-xs text-stone-500">Used as the default delivery address on all your orders.</p>
            </div>

            <div class="pt-4 flex flex-wrap justify-end gap-3 border-t border-stone-200">
                <a href="profile.php" class="rounded-xl border border-stone-300 bg-white px-5 py-3 text-sm font-bold text-stone-700 hover:bg-stone-50">Cancel</a>
                <button type="submit" class="rounded-xl bg-emerald-800 px-6 py-3 text-sm font-bold text-white hover:bg-emerald-700 transition shadow-sm">
                    Save Details
                </button>
            </div>
        </form>

        <p class="mt-6 text-xs text-stone-500 text-center">
            Need to change your password or upload a profile picture? Use the
            <a href="profile.php" class="font-bold text-emerald-800 hover:underline">account settings page</a>.
        </p>
    </main>
</body>
</html>