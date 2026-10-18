<?php
require_once 'config.php';

$staffAuthStmt = $pdo->prepare("SELECT role, status FROM users WHERE id = ? LIMIT 1");
$staffAuthStmt->execute([$_SESSION['user_id'] ?? 0]);
$staffAccount = $staffAuthStmt->fetch();

if (!$staffAccount || strtolower(trim((string) ($staffAccount['role'] ?? ''))) !== 'staff') {
    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['name'], $_SESSION['status']);
    header('Location: login.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$query = "SELECT id, name, email, contact_number, gender, address, status, created_at
          FROM users WHERE LOWER(role) = 'customer'";
$params = [];
if ($search !== '') {
    $query .= ' AND (name LIKE ? OR email LIKE ? OR contact_number LIKE ?)';
    $searchTerm = '%' . $search . '%';
    $params = [$searchTerm, $searchTerm, $searchTerm];
}
$query .= ' ORDER BY created_at DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Directory - Staff Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/system-theme.css">
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen flex flex-col font-sans">
    <main class="max-w-6xl mx-auto px-4 py-8 flex-grow w-full">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-5">
                <?php $brandTone = 'light'; include 'brand_logo.php'; ?>
                <h1 class="text-2xl font-bold text-emerald-950">Registered Customers List</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="customer_history.php" class="rounded-xl border border-emerald-800 bg-white px-4 py-2 text-xs font-bold text-emerald-900 hover:bg-emerald-50">Order history report</a>
                <a href="export_customers.php" class="rounded-xl bg-amber-500 px-4 py-2 text-xs font-bold text-emerald-950 hover:bg-amber-400">Export CSV</a>
                <a href="staff_dashboard.php" class="bg-stone-200 hover:bg-stone-300 text-stone-800 px-4 py-2 rounded-xl text-xs font-bold">Back to Dashboard</a>
            </div>
        </div>

        <form method="GET" class="mb-5 flex flex-wrap gap-2">
            <label class="sr-only" for="customer-search">Search customers</label>
            <input id="customer-search" name="search" type="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, email, or phone" class="min-w-64 flex-1 rounded-lg border border-stone-300 bg-white px-4 py-2.5 text-sm focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">
            <button type="submit" class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700">Search</button>
            <?php if ($search !== ''): ?><a href="staff_customers.php" class="rounded-lg border border-stone-300 bg-white px-4 py-2.5 text-sm font-semibold text-stone-700">Clear</a><?php endif; ?>
        </form>

        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
            <table class="w-full min-w-[850px] text-left text-sm text-stone-700">
                <thead class="bg-stone-50 uppercase text-xs text-stone-500 font-bold border-b border-stone-200">
                    <tr>
                        <th class="p-4">Customer ID</th>
                        <th class="p-4">Full Name</th>
                        <th class="p-4">Email</th>
                        <th class="p-4">Phone</th>
                        <th class="p-4">Gender</th>
                        <th class="p-4">Address</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Registered Date</th>
                        <th class="p-4">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200">
                    <?php if (empty($customers)): ?>
                        <tr><td colspan="9" class="p-8 text-center text-stone-500">No registered customers found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($customers as $c): ?>
                        <tr class="hover:bg-stone-50">
                            <td class="p-4 font-bold text-emerald-950">#CUST-<?= (int) $c['id'] ?></td>
                            <td class="p-4 font-semibold"><?= h($c['name']) ?></td>
                            <td class="p-4"><?= h($c['email']) ?></td>
                            <td class="p-4"><?= h($c['contact_number'] ?? '') ?></td>
                            <td class="p-4"><?= h($c['gender'] ?? '') ?></td>
                            <td class="max-w-xs p-4"><?= h($c['address'] ?? '') ?></td>
                            <td class="p-4"><?= h($c['status'] ?? '') ?></td>
                            <td class="p-4 text-xs text-stone-500"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                            <td class="p-4">
                                <a href="customer_detail.php?id=<?= (int) $c['id'] ?>" class="inline-flex items-center rounded-lg bg-emerald-800 px-3 py-2 text-[11px] font-bold text-white hover:bg-emerald-700">View Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>