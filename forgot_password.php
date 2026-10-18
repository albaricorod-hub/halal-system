<?php
require_once 'config.php';

$notice = '';
$noticeType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('forgot_csrf');
    $email = trim((string) ($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $notice = 'Enter a valid email address.';
        $noticeType = 'error';
    } else {
        $notice = 'If an account matches that email, contact the system administrator to complete the password reset securely.';
        $noticeType = 'success';
    }
}

$forgotCsrf = csrfToken('forgot_csrf');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/system-theme.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Help - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-emerald-950 via-emerald-900 to-stone-100 flex items-center justify-center px-4 py-10">
    <main class="w-full max-w-md rounded-3xl bg-white p-7 sm:p-9 shadow-2xl border border-white/70">
        <div class="mb-6"><?php $brandTone = 'light'; include 'brand_logo.php'; ?></div>

        <a href="login.php" class="text-sm font-bold text-emerald-800 hover:text-emerald-600">&larr; Back to sign in</a>

        <div class="mt-8">
            <p class="text-xs uppercase tracking-[0.2em] text-amber-600 font-bold">Account access</p>
            <h1 class="text-3xl font-extrabold text-emerald-950 mt-2">Need password help?</h1>
            <p class="text-sm text-stone-600 mt-3 leading-relaxed">Enter your account email and the administrator can verify your identity before resetting access.</p>
        </div>

        <?php if ($notice !== ''): ?>
            <div class="mt-6 rounded-2xl border p-4 text-sm font-semibold <?= $noticeType === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-900' ?>">
                <?= h($notice) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="mt-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= h($forgotCsrf) ?>">
            <div>
                <label class="block text-sm font-bold text-stone-700" for="email">Account email</label>
                <input id="email" name="email" type="email" required value="<?= h($_POST['email'] ?? '') ?>"
                       class="mt-1 w-full rounded-xl border border-stone-300 px-4 py-3 outline-none focus:border-emerald-700 focus:ring-2 focus:ring-emerald-100"
                       placeholder="you@example.com">
            </div>
            <button type="submit" class="w-full rounded-xl bg-emerald-800 hover:bg-emerald-700 text-white px-4 py-3 font-extrabold transition">
                Request password help
            </button>
        </form>
    </main>
</body>
</html>