<?php
require_once 'config.php';
checkAuth(['admin', 'staff']);

$customerId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!$customerId) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin_dashboard.php' : 'staff_customers.php'));
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, contact_number, gender, address, role, status, created_at, profile_image FROM users WHERE id = ? AND LOWER(role) = 'customer' LIMIT 1");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin_dashboard.php' : 'staff_customers.php'));
    exit;
}

$backLink = $_SESSION['role'] === 'admin' ? 'admin_dashboard.php' : 'staff_customers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($customer['name']) ?> - Customer Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/system-theme.css">
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen">
    <main class="mx-auto max-w-5xl px-4 py-8">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Customer profile</p>
                <h1 class="mt-2 text-3xl font-extrabold text-emerald-950">Customer Details</h1>
            </div>
            <a href="<?= h($backLink) ?>" class="rounded-xl border border-stone-300 bg-white px-4 py-2 text-sm font-bold text-stone-700 hover:bg-stone-50">&larr; Back to list</a>
        </div>
        <div class="overflow-hidden rounded-3xl border border-stone-200 bg-white shadow-sm">
            <div class="bg-emerald-950 p-6 text-white">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-4">
                        <?php if (!empty($customer['profile_image'])): ?>
                            <img src="<?= h($customer['profile_image']) ?>" alt="<?= h($customer['name']) ?>" class="h-20 w-20 rounded-full border-2 border-amber-400 object-cover shadow-lg">
                        <?php else: ?>
                            <div class="flex h-20 w-20 items-center justify-center rounded-full bg-amber-500 text-2xl font-extrabold text-emerald-950 shadow-lg"><?= h(strtoupper(substr($customer['name'], 0, 1))) ?></div>
                        <?php endif; ?>
                        <div>
                            <h2 class="text-2xl font-extrabold text-amber-400"><?= h($customer['name']) ?></h2>
                            <p class="text-xs uppercase tracking-[0.18em] text-stone-300">Customer ID #CUST-<?= (int) $customer['id'] ?></p>
                        </div>
                    </div>
                    <span class="inline-flex rounded-full border border-emerald-700 bg-emerald-900/80 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.2em] text-emerald-100"><?= h($customer['status'] ?? 'active') ?></span>
                </div>
            </div>
            <div class="p-6">
                <div class="rounded-2xl border border-stone-200 bg-stone-50 p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><p class="text-[11px] font-bold uppercase tracking-wider text-stone-500">Email</p><p class="mt-1 text-sm font-semibold text-stone-800"><?= h($customer['email']) ?></p></div>
                        <div><p class="text-[11px] font-bold uppercase tracking-wider text-stone-500">Phone</p><p class="mt-1 text-sm font-semibold text-stone-800"><?= h($customer['contact_number'] ?? 'Not provided') ?></p></div>
                        <div><p class="text-[11px] font-bold uppercase tracking-wider text-stone-500">Gender</p><p class="mt-1 text-sm font-semibold text-stone-800"><?= h($customer['gender'] ?? 'Not provided') ?></p></div>
                        <div><p class="text-[11px] font-bold uppercase tracking-wider text-stone-500">Registered</p><p class="mt-1 text-sm font-semibold text-stone-800"><?= date('M d, Y', strtotime($customer['created_at'])) ?></p></div>
                        <div class="sm:col-span-2"><p class="text-[11px] font-bold uppercase tracking-wider text-stone-500">Address</p><p class="mt-1 text-sm font-semibold text-stone-800"><?= h($customer['address'] ?? 'Not provided') ?></p></div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>