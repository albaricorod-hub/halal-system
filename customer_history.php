<?php
require_once 'config.php';
checkAuth(['admin', 'staff']);

$userRole   = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$customerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT) ?: 0;
$search     = trim((string) ($_GET['search'] ?? ''));
$filters    = [];
$params     = [];

$query = "SELECT o.id AS order_id, o.created_at, u.id AS customer_id, u.name AS customer_name,
                 u.email, u.contact_number, o.total_amount,
                 CASE WHEN LOWER(o.order_status) = 'pending' AND LOWER(o.status) <> 'pending' THEN o.status
                     ELSE COALESCE(NULLIF(o.order_status, ''), NULLIF(o.status, ''), 'Pending') END AS order_status,
                 COALESCE(NULLIF(p.payment_method, ''), NULLIF(o.payment_method, ''), 'COD') AS payment_method,
                 COALESCE(NULLIF(p.payment_status, ''), NULLIF(o.payment_status, ''), 'unpaid') AS payment_status
          FROM orders o
          JOIN users u ON u.id = o.user_id
          LEFT JOIN payments p ON p.order_id = o.id
          WHERE LOWER(u.role) = 'customer'";
if ($customerId > 0) {
    $filters[] = 'u.id = ?';
    $params[]  = $customerId;
}
if ($search !== '') {
    $filters[] = '(u.name LIKE ? OR u.email LIKE ? OR u.contact_number LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}
if ($filters) {
    $query .= ' AND ' . implode(' AND ', $filters);
}
$query .= ' ORDER BY o.created_at DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$csvSafe = static function (string $v): string {
    if ($v !== '' && preg_match('/^[=+\-@]/', $v)) {
        return "'" . $v;
    }
    return $v;
};

if (($_GET['download'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=customer_order_history_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Order ID', 'Customer ID', 'Customer Name', 'Email', 'Phone', 'Order Date', 'Total', 'Order Status', 'Payment Method', 'Payment Status']);
    foreach ($orders as $order) {
        fputcsv($output, [
            $order['order_id'],
            $order['customer_id'],
            $csvSafe((string) $order['customer_name']),
            $csvSafe((string) $order['email']),
            $csvSafe((string) ($order['contact_number'] ?? '')),
            $order['created_at'],
            $order['total_amount'],
            $order['order_status'],
            $order['payment_method'],
            $order['payment_status'],
        ]);
    }
    fclose($output);
    exit;
}

$customers = $pdo->query("SELECT id, name, email FROM users WHERE LOWER(role) = 'customer' ORDER BY name")->fetchAll();
$paymentLabel = static fn(string $code): string => in_array($code, ['COD', 'Cash on Delivery'], true) ? 'Cash on Delivery' : $code;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Order History - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-stone-100 text-stone-800">
    <?php include 'navbar.php'; ?>
    <main class="mx-auto max-w-7xl px-4 py-8">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Customer reports</p>
                <h1 class="mt-2 text-3xl font-extrabold text-emerald-950">Order history</h1>
            </div>
            <a href="<?= h('customer_history.php?' . http_build_query(array_filter(['customer_id' => $customerId ?: null, 'search' => $search ?: null, 'download' => 'csv']))) ?>" class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700">Export CSV</a>
        </div>

        <form method="GET" class="mb-5 grid gap-3 rounded-2xl border border-stone-200 bg-white p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto_auto]">
            <select name="customer_id" class="min-w-0 rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm">
                <option value="">All customers</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int) $customer['id'] ?>" <?= $customerId === (int) $customer['id'] ? 'selected' : '' ?>><?= h($customer['name'] . ' · ' . $customer['email']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="search" value="<?= h($search) ?>" placeholder="Search name, email, phone" class="min-w-0 rounded-lg border border-stone-300 px-3 py-2.5 text-sm">
            <button type="submit" class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-bold text-white">Filter</button>
            <a href="customer_history.php" class="rounded-lg border border-stone-300 px-4 py-2.5 text-center text-sm font-semibold text-stone-700">Clear</a>
        </form>

        <div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm">
            <table class="w-full min-w-[1000px] text-left text-sm">
                <thead class="bg-emerald-950 text-xs uppercase tracking-wide text-white">
                    <tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Order Status</th><th class="px-4 py-3">Payment</th><th class="px-4 py-3">Receipt</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    <?php if (!$orders): ?>
                        <tr><td colspan="7" class="px-4 py-10 text-center text-stone-500">No customer orders match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr class="align-top hover:bg-stone-50">
                                <td class="px-4 py-3 font-bold text-emerald-950">#ORD-<?= (int) $order['order_id'] ?></td>
                                <td class="px-4 py-3"><span class="font-semibold"><?= h($order['customer_name']) ?></span><span class="block text-xs text-stone-500"><?= h($order['email']) ?> · <?= h($order['contact_number'] ?? '') ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-stone-500"><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></td>
                                <td class="px-4 py-3 font-bold">₱<?= number_format((float) $order['total_amount'], 2) ?></td>
                                <td class="px-4 py-3"><?= h($order['order_status']) ?></td>
                                <td class="px-4 py-3 text-xs"><span class="block font-semibold"><?= h($paymentLabel((string) $order['payment_method'])) ?></span><span class="text-stone-500"><?= h($order['payment_status']) ?></span></td>
                                <td class="px-4 py-3"><a href="order_tracker.php?id=<?= (int) $order['order_id'] ?>" class="rounded-md bg-emerald-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700"><?= in_array($userRole, ['admin', 'staff'], true) ? 'View / Print' : 'View Details' ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-stone-500"><?= count($orders) ?> order record<?= count($orders) === 1 ? '' : 's' ?></p>
    </main>
</body>
</html>