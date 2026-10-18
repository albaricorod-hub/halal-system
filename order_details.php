<?php
require_once 'config.php';
checkAuth();
ensurePaymentProofColumn($pdo);
ensureOrderItemCustomizationColumns($pdo);

$orderId  = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$userId   = (int) $_SESSION['user_id'];
$userRole = strtolower(trim((string) ($_SESSION['role'] ?? 'customer')));

if (!$orderId) {
    http_response_code(400);
    exit('Invalid order.');
}

$isStaffOrAdmin = in_array($userRole, ['admin', 'staff'], true);

$stmt = $pdo->prepare("
    SELECT o.*, u.name AS customer_name, u.email AS customer_email,
           COALESCE(NULLIF(p.payment_method, ''), NULLIF(o.payment_method, ''), 'COD') AS payment_method,
           COALESCE(NULLIF(p.payment_status, ''), NULLIF(o.payment_status, ''), 'unpaid') AS payment_status,
           p.reference_number, p.proof_path, p.amount AS payment_amount
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN payments p ON p.order_id = o.id
    WHERE o.id = ?
    LIMIT 1
");
$params = [$orderId];
if (!$isStaffOrAdmin) {
    $stmt = $pdo->prepare("
        SELECT o.*, u.name AS customer_name, u.email AS customer_email,
               COALESCE(NULLIF(p.payment_method, ''), NULLIF(o.payment_method, ''), 'COD') AS payment_method,
               COALESCE(NULLIF(p.payment_status, ''), NULLIF(o.payment_status, ''), 'unpaid') AS payment_status,
               p.reference_number, p.proof_path, p.amount AS payment_amount
        FROM orders o
        JOIN users u ON u.id = o.user_id
        LEFT JOIN payments p ON p.order_id = o.id
        WHERE o.id = ? AND o.user_id = ?
        LIMIT 1
    ");
    $params = [$orderId, $userId];
}
$stmt->execute($params);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Order not found or you do not have permission to view it.');
}

$itemsStmt = $pdo->prepare("
    SELECT oi.quantity, oi.price, oi.custom_cut, oi.custom_toppings,
           f.name, f.ingredients
    FROM order_items oi
    JOIN foods f ON f.id = oi.food_id
    WHERE oi.order_id = ?
    ORDER BY f.name
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

$statusLabels = [
    'pending'          => 'Order Received',
    'confirmed'        => 'Confirmed',
    'preparing'        => 'Preparing Your Order',
    'ready'            => 'Ready for Pickup',
    'out for delivery' => 'Out for Delivery',
    'completed'        => 'Completed',
    'cancelled'        => 'Cancelled',
];
$orderStatus = strtolower(trim((string) ($order['order_status'] ?? 'pending')));
if ($orderStatus === 'pending' && strtolower(trim((string) ($order['status'] ?? 'pending'))) !== 'pending') {
    $orderStatus = strtolower(trim((string) $order['status']));
}
$statusSteps  = ['pending', 'confirmed', 'preparing', 'ready', 'out for delivery', 'completed'];
$currentStep  = array_search($orderStatus, $statusSteps, true);
$currentStep  = $currentStep === false ? -1 : $currentStep;

$paymentMethod      = (string) $order['payment_method'];
$paymentMethodLabel = in_array($paymentMethod, ['COD', 'Cash on Delivery'], true) ? 'Cash on Delivery' : $paymentMethod;
$paymentStatus      = (string) $order['payment_status'];

$trackerUrl = 'order_tracker.php?id=' . $orderId;
$backUrl    = match ($userRole) {
    'admin'    => 'admin_dashboard.php',
    'staff'    => 'staff_dashboard.php',
    'supplier' => 'suppliers_dashboard.php',
    default    => 'order.php',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #ORD-<?= (int) $order['id'] ?> Details - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/system-theme.css">
    <style>
        @media print {
            body * { visibility: hidden; }
            #printableOrder, #printableOrder * { visibility: visible; }
            #printableOrder { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen p-6">
    <div class="max-w-3xl mx-auto space-y-6">
        <div class="flex flex-wrap justify-between items-center gap-3 no-print">
            <a href="<?= h($backUrl) ?>" class="text-sm font-bold text-emerald-800 hover:text-emerald-600">&larr; Back</a>
            <div class="flex flex-wrap gap-2">
                <a href="<?= h($trackerUrl) ?>" class="rounded-xl bg-amber-500 hover:bg-amber-400 text-emerald-950 px-4 py-2 text-xs font-extrabold transition shadow-sm border border-amber-600">
                    Track &amp; Manage Order &rarr;
                </a>
                <button type="button" onclick="window.print()" class="rounded-xl bg-emerald-800 hover:bg-emerald-700 text-white px-4 py-2 text-xs font-bold transition shadow-sm">
                    Print
                </button>
            </div>
        </div>

        <div id="printableOrder" class="bg-white p-6 sm:p-8 rounded-2xl border border-stone-200 shadow-sm">
            <div class="flex justify-between items-start border-b pb-4 mb-5">
                <div>
                    <?php $brandTone = 'light'; include 'brand_logo.php'; ?>
                    <h1 class="text-2xl font-extrabold text-emerald-950 mt-2">Order #ORD-<?= (int) $order['id'] ?></h1>
                    <p class="text-xs text-stone-500 mt-1">Placed on <?= date('M d, Y \a\t h:i A', strtotime($order['created_at'])) ?></p>
                </div>
                <span class="px-3 py-1.5 rounded-full text-xs font-extrabold uppercase
                    <?= $orderStatus === 'cancelled'        ? 'bg-rose-100 text-rose-800'       : '' ?>
                    <?= $orderStatus === 'completed'        ? 'bg-emerald-100 text-emerald-800' : '' ?>
                    <?= $orderStatus === 'pending'          ? 'bg-amber-100 text-amber-800'     : '' ?>
                    <?= $orderStatus === 'preparing'        ? 'bg-blue-100 text-blue-800'       : '' ?>
                    <?= $orderStatus === 'out for delivery' ? 'bg-indigo-100 text-indigo-800'   : '' ?>
                    <?= !in_array($orderStatus, ['cancelled','completed','pending','preparing','out for delivery'], true) ? 'bg-stone-100 text-stone-700' : '' ?>
                ">
                    <?= h($statusLabels[$orderStatus] ?? ucfirst($orderStatus)) ?>
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm mb-6">
                <div class="rounded-xl border border-stone-200 bg-stone-50 p-4">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-stone-500">Customer</p>
                    <p class="mt-1 font-bold text-emerald-950"><?= h($order['customer_name']) ?></p>
                    <p class="text-xs text-stone-600 mt-0.5"><?= h($order['customer_email']) ?></p>
                </div>
                <div class="rounded-xl border border-stone-200 bg-stone-50 p-4">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-stone-500">Delivery Address</p>
                    <p class="mt-1 text-sm text-stone-800"><?= h($order['delivery_address'] ?: 'Not provided') ?></p>
                </div>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-4 mb-6">
                <h2 class="text-xs uppercase tracking-wider font-bold text-emerald-800 mb-3">Payment</h2>
                <dl class="grid sm:grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-stone-500 text-xs uppercase font-bold">Method</dt>
                        <dd class="text-stone-800 font-semibold"><?= h($paymentMethodLabel) ?></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500 text-xs uppercase font-bold">Status</dt>
                        <dd class="font-bold text-amber-700"><?= h(ucfirst($paymentStatus)) ?></dd>
                    </div>
                    <?php if ($paymentMethod === 'GCash'): ?>
                        <div class="sm:col-span-2">
                            <dt class="text-stone-500 text-xs uppercase font-bold">GCash Reference</dt>
                            <dd class="text-stone-800 font-mono"><?= h($order['reference_number'] ?: 'Not submitted') ?></dd>
                        </div>
                        <?php if (!empty($order['proof_path']) && is_file(__DIR__ . '/' . $order['proof_path'])): ?>
                            <div class="sm:col-span-2">
                                <dt class="text-stone-500 text-xs uppercase font-bold">Payment Screenshot</dt>
                                <dd class="mt-1">
                                    <a href="payment_proof.php?id=<?= (int) $orderId ?>" target="_blank" rel="noopener" class="text-xs font-bold text-blue-700 underline">View screenshot</a>
                                    <?php if ($isStaffOrAdmin): ?>
                                        <img src="payment_proof.php?id=<?= (int) $orderId ?>" alt="Customer GCash proof for order <?= (int) $orderId ?>" class="mt-2 max-h-72 rounded-lg border border-stone-200 object-contain">
                                    <?php endif; ?>
                                </dd>
                            </div>
                        <?php elseif ($paymentMethod === 'GCash'): ?>
                            <div class="sm:col-span-2">
                                <p class="text-xs font-bold text-rose-700">Payment screenshot not submitted.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </dl>
            </div>

            <?php if ($orderStatus !== 'cancelled'): ?>
                <div class="mb-6">
                    <h2 class="text-xs uppercase tracking-wider font-bold text-emerald-800 mb-3">Order Progress</h2>
                    <ol class="grid grid-cols-3 sm:grid-cols-6 gap-2">
                        <?php foreach ($statusSteps as $idx => $step): ?>
                            <?php $isComplete = $currentStep >= $idx; ?>
                            <li class="flex flex-col items-center text-center">
                                <div class="flex h-9 w-9 items-center justify-center rounded-full border-2 font-extrabold text-xs
                                    <?= $isComplete ? 'border-emerald-800 bg-emerald-800 text-white' : 'border-stone-300 bg-stone-50 text-stone-400' ?>">
                                    <?= $isComplete ? '&#10003;' : ($idx + 1) ?>
                                </div>
                                <p class="mt-1 text-[10px] font-bold <?= $isComplete ? 'text-emerald-900' : 'text-stone-400' ?>"><?= h($statusLabels[$step]) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endif; ?>

            <div>
                <h2 class="text-xs uppercase tracking-wider font-bold text-emerald-800 mb-3">Ordered Items</h2>
                <div class="divide-y divide-stone-200 border-t border-b border-stone-200">
                    <?php if (empty($items)): ?>
                        <p class="py-4 text-sm text-stone-500 italic">No items found for this order.</p>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <div class="flex justify-between gap-3 py-3 text-sm">
                                <div class="min-w-0">
                                    <p class="font-semibold text-stone-800">
                                        <?= h($item['name']) ?> &times; <?= (int) $item['quantity'] ?>
                                        <?php if ((float) $item['price'] === 0.0): ?>
                                            <span class="ml-2 inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-800">Free reward</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (($item['custom_cut'] ?? 'none') !== 'none'): ?>
                                        <p class="text-xs text-emerald-800 mt-0.5">Requested cut: <?= h(halalMeatCutOptions()[$item['custom_cut']] ?? 'Custom cut') ?></p>
                                    <?php endif; ?>
                                    <?php
                                    $selectedToppings = json_decode((string) ($item['custom_toppings'] ?? '[]'), true);
                                    $toppingOptions = halalDishCustomizationOptions($item);
                                    $labels = is_array($selectedToppings) ? array_values(array_intersect_key($toppingOptions, array_flip($selectedToppings))) : [];
                                    ?>
                                    <?php if ($labels): ?>
                                        <p class="text-xs text-emerald-800 mt-0.5">Options: <?= h(implode(', ', $labels)) ?></p>
                                    <?php endif; ?>
                                </div>
                                <p class="shrink-0 font-bold text-stone-800">₱<?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="mt-4 flex justify-between items-center bg-emerald-50 border border-emerald-200 rounded-xl p-4">
                    <span class="text-sm font-bold text-emerald-950">Total Amount</span>
                    <span class="text-xl font-extrabold text-emerald-900">₱<?= number_format((float) $order['total_amount'], 2) ?></span>
                </div>
            </div>
        </div>

        <p class="text-xs text-stone-500 text-center no-print">
            Need to update status, confirm payment, or send a message? Use the
            <a href="<?= h($trackerUrl) ?>" class="font-bold text-emerald-800 hover:underline">order tracker</a>.
        </p>
    </div>
</body>
</html>