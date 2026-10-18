<?php
require_once 'config.php';
checkAuth();
ensureOrderItemCustomizationColumns($pdo);
ensurePaymentProofColumn($pdo);

$orderId  = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$userId   = (int) $_SESSION['user_id'];
$userRole = strtolower(trim((string) ($_SESSION['role'] ?? 'customer')));

if (!$orderId) {
    http_response_code(400);
    exit('Invalid Order ID.');
}

$isStaffOrAdmin = in_array($userRole, ['admin', 'staff'], true);

$orderQuery = "SELECT o.*, u.name, u.email,
                      COALESCE(NULLIF(p.payment_method, ''), NULLIF(o.payment_method, ''), 'COD') AS payment_method,
                      COALESCE(NULLIF(p.payment_status, ''), NULLIF(o.payment_status, ''), 'unpaid') AS payment_status,
                      p.reference_number, p.proof_path
               FROM orders o
               JOIN users u ON o.user_id = u.id
               LEFT JOIN payments p ON p.order_id = o.id
               WHERE o.id = ?";
$orderParams = [$orderId];
if (!$isStaffOrAdmin) {
    $orderQuery .= ' AND o.user_id = ?';
    $orderParams[] = $userId;
}

$stmt = $pdo->prepare($orderQuery);
$stmt->execute($orderParams);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Order not found or you do not have permission to view it.');
}

$orderItemsStmt = $pdo->prepare('SELECT oi.*, f.name, f.ingredients FROM order_items oi JOIN foods f ON f.id = oi.food_id WHERE oi.order_id = ? ORDER BY f.name');
$orderItemsStmt->execute([$orderId]);
$orderItems = $orderItemsStmt->fetchAll();

$paymentMethod      = (string) ($order['payment_method'] ?? 'COD');
$paymentMethodLabel = in_array($paymentMethod, ['COD', 'Cash on Delivery'], true) ? 'Cash on Delivery' : $paymentMethod;
$paymentStatus      = (string) ($order['payment_status'] ?? 'unpaid');

$orderStatus = strtolower(trim((string) ($order['order_status'] ?? 'pending')));
if ($orderStatus === 'pending' && strtolower(trim((string) ($order['status'] ?? 'pending'))) !== 'pending') {
    $orderStatus = strtolower(trim((string) $order['status']));
}

$statusLabels = [
    'pending'          => 'Order Received',
    'confirmed'        => 'Confirmed',
    'preparing'        => 'Preparing Your Order',
    'ready'            => 'Ready for Pickup',
    'out for delivery' => 'Out for Delivery',
    'completed'        => 'Completed',
    'cancelled'        => 'Cancelled',
];
$statusSteps  = ['pending', 'confirmed', 'preparing', 'ready', 'out for delivery', 'completed'];
$currentStep  = array_search($orderStatus, $statusSteps, true);
$currentStep  = $currentStep === false ? -1 : $currentStep;

$paymentError   = $_SESSION['payment_error']   ?? '';
$paymentSuccess = $_SESSION['payment_success'] ?? '';
unset($_SESSION['payment_error'], $_SESSION['payment_success']);

$orderCsrf = csrfToken('order_csrf');

$homeUrl = 'index.php';
if ($userRole === 'staff') {
    $homeUrl = 'staff_dashboard.php';
} elseif ($userRole === 'admin') {
    $homeUrl = 'admin_dashboard.php';
} elseif ($userRole === 'customer') {
    $homeUrl = 'order.php';
}

/* ------------------------------------------------------------------ */
/* Customer GCash submission                                          */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment_reference']) && $userRole === 'customer') {
    requireCsrf('order_csrf');

    $referenceNumber = is_string($_POST['reference_number'] ?? null) ? trim($_POST['reference_number']) : '';
    $newProofPath    = null;

    if ($paymentMethod !== 'GCash' || $referenceNumber === '' || strlen($referenceNumber) > 100 || strtolower($paymentStatus) === 'confirmed') {
        $_SESSION['payment_error'] = 'Enter a valid GCash reference number.';
    } else {
        try {
            $newProofPath = storeGcashPaymentProof($_FILES['payment_proof'] ?? []);
            ensureSystemActivityTable($pdo);
            $pdo->beginTransaction();

            $paymentExists = $pdo->prepare("SELECT id FROM payments WHERE order_id = ? AND payment_method = 'GCash' AND payment_status <> 'Confirmed' FOR UPDATE");
            $paymentExists->execute([$orderId]);
            $paymentId = $paymentExists->fetchColumn();

            if (!$paymentId) {
                $pdo->rollBack();
                @unlink(__DIR__ . '/' . $newProofPath);
                $_SESSION['payment_error'] = 'GCash payment details could not be found for this order.';
                header('Location: order_tracker.php?id=' . $orderId);
                exit;
            }

            $updateReference = $pdo->prepare("UPDATE payments SET reference_number = ?, proof_path = ?, payment_status = 'Pending Verification' WHERE order_id = ? AND payment_method = 'GCash'");
            $updateReference->execute([$referenceNumber, $newProofPath, $orderId]);
            $pdo->prepare("UPDATE orders SET payment_status = 'unpaid' WHERE id = ?")->execute([$orderId]);

            createNotification($pdo, $userId, 'Payment submitted', "GCash reference and screenshot for order #ORD-{$orderId} were submitted for verification.");
            notifyAdmins($pdo, "GCash reference and screenshot submitted for order #ORD-{$orderId}; payment is ready for verification.");
            recordSystemActivity($pdo, 'GCash payment proof submitted', "Customer submitted a reference and screenshot for order #ORD-{$orderId}.");
            $pdo->commit();

            if (!empty($order['proof_path']) && is_file(__DIR__ . '/' . $order['proof_path'])) {
                @unlink(__DIR__ . '/' . $order['proof_path']);
            }
            $_SESSION['payment_success'] = 'GCash reference and screenshot submitted for staff verification.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newProofPath !== null && is_file(__DIR__ . '/' . $newProofPath)) {
                @unlink(__DIR__ . '/' . $newProofPath);
            }
            error_log('GCash submission failed: ' . $exception->getMessage());
            $_SESSION['payment_error'] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Unable to submit payment details. Please try again.';
        }
    }
    header('Location: order_tracker.php?id=' . $orderId);
    exit;
}

/* ------------------------------------------------------------------ */
/* Staff & admin actions                                              */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isStaffOrAdmin
    && (isset($_POST['update_status']) || isset($_POST['confirm_payment']) || isset($_POST['send_notification']))) {
    requireCsrf('order_csrf');

    if (isset($_POST['update_status'])) {
        $status = strtolower(trim((string) ($_POST['status'] ?? '')));
        $allowedStatuses = array_keys($statusLabels);
        if (in_array($status, $allowedStatuses, true) && $status !== $orderStatus) {
            $requiresGcashVerification = $paymentMethod === 'GCash'
                && !in_array(strtolower($paymentStatus), ['paid', 'confirmed'], true);
            if ($requiresGcashVerification && $status !== 'cancelled') {
                $_SESSION['payment_error'] = 'Staff must verify the GCash reference and screenshot before processing this order.';
                header('Location: order_tracker.php?id=' . $orderId);
                exit;
            }
            $legacyStatus = [
                'pending' => 'pending', 'confirmed' => 'pending',
                'preparing' => 'preparing', 'ready' => 'preparing',
                'out for delivery' => 'preparing', 'completed' => 'completed',
                'cancelled' => 'cancelled',
            ][$status];
            $databaseOrderStatus = [
                'pending' => 'Pending', 'confirmed' => 'Confirmed',
                'preparing' => 'Preparing', 'ready' => 'Ready',
                'out for delivery' => 'Out for Delivery', 'completed' => 'Completed',
                'cancelled' => 'Cancelled',
            ][$status];
            ensureSystemActivityTable($pdo);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE orders SET order_status = ?, status = ? WHERE id = ?')->execute([$databaseOrderStatus, $legacyStatus, $orderId]);
            createNotification($pdo, (int) $order['user_id'], 'Order status updated', "Order #ORD-{$orderId} is now " . ($statusLabels[$status] ?? ucfirst($status)) . '.');
            recordSystemActivity($pdo, 'Order status updated', "Order #ORD-{$orderId} set to {$status}.");
            $pdo->commit();
        }
    }

    if (isset($_POST['confirm_payment'])) {
        if ($paymentMethod === 'GCash' && (trim((string) ($order['reference_number'] ?? '')) === '' || empty($order['proof_path']) || !is_file(__DIR__ . '/' . $order['proof_path']))) {
            $_SESSION['payment_error'] = 'The customer must submit both the GCash reference number and screenshot before payment can be accepted.';
        } else {
            ensureSystemActivityTable($pdo);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE payments SET payment_status = 'Confirmed' WHERE order_id = ?")->execute([$orderId]);
            createNotification($pdo, (int) $order['user_id'], 'Payment confirmed', "Payment for order #ORD-{$orderId} was accepted by staff.");
            recordSystemActivity($pdo, 'Payment confirmed', "Payment for order #ORD-{$orderId} confirmed by staff.");
            $pdo->commit();
            $_SESSION['payment_success'] = 'Payment accepted and customer notified.';
        }
    }

    if (isset($_POST['send_notification'])) {
        $msg = trim((string) ($_POST['notif_message'] ?? ''));
        if ($msg !== '') {
            createNotification($pdo, (int) $order['user_id'], 'Message from the kitchen', $msg);
            recordSystemActivity($pdo, 'Customer notified', "Customer for order #ORD-{$orderId} was notified by staff.");
        }
    }
    header('Location: order_tracker.php?id=' . $orderId);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #ORD-<?= h($order['id']) ?> Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/system-theme.css">
    <style>
        @media print {
            body * { visibility: hidden; }
            #printableReceipt, #printableReceipt * { visibility: visible; }
            #printableReceipt { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen p-6">
    <div class="max-w-3xl mx-auto space-y-6">
        <?php if ($paymentError !== ''): ?>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800 no-print" role="alert"><?= h($paymentError) ?></div>
        <?php endif; ?>
        <?php if ($paymentSuccess !== ''): ?>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 no-print" role="status"><?= h($paymentSuccess) ?></div>
        <?php endif; ?>

        <div id="printableReceipt" class="bg-white p-6 rounded-2xl border border-stone-200 shadow-sm">
            <div class="flex justify-between items-center border-b pb-4 mb-4">
                <div>
                    <?php $brandTone = 'light'; include 'brand_logo.php'; ?>
                    <h1 class="text-xl font-extrabold text-emerald-950">Receipt / Order Details</h1>
                    <p class="text-xs text-stone-500">Order #ORD-<?= h($order['id']) ?> | <?= date('M d, Y h:i A', strtotime($order['created_at'])) ?></p>
                </div>
                <div class="flex items-center gap-2 no-print">
                    <a href="<?= h($homeUrl) ?>" class="bg-stone-200 hover:bg-stone-300 text-stone-800 px-4 py-2 rounded-xl text-xs font-bold transition">&larr; Back</a>
                    <?php if ($isStaffOrAdmin): ?>
                        <button type="button" onclick="window.print()" class="bg-emerald-800 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-bold transition shadow-sm">Print Receipt</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 text-xs mb-6">
                <div>
                    <p class="font-bold text-stone-500 uppercase">Customer Name</p>
                    <p class="font-bold text-stone-800 text-sm"><?= h($order['name']) ?></p>
                </div>
                <div>
                    <p class="font-bold text-stone-500 uppercase">Selected Payment</p>
                    <p class="font-bold text-stone-800 text-sm"><?= h($paymentMethodLabel) ?> (<?= h(strtoupper($paymentStatus)) ?>)</p>
                    <?php if ($paymentMethod === 'GCash'): ?>
                        <p class="mt-1 text-xs text-stone-600">Reference: <span class="font-bold text-emerald-900"><?= h($order['reference_number'] ?: 'Not submitted') ?></span></p>
                        <?php if (!empty($order['proof_path']) && is_file(__DIR__ . '/' . $order['proof_path'])): ?>
                            <a href="payment_proof.php?id=<?= (int) $orderId ?>" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-2 text-xs font-bold text-blue-700 underline">View payment screenshot</a>
                            <?php if ($isStaffOrAdmin): ?>
                                <img src="payment_proof.php?id=<?= (int) $orderId ?>" alt="Customer GCash payment screenshot for order <?= (int) $orderId ?>" class="mt-2 max-h-72 rounded-lg border border-stone-200 object-contain">
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="mt-1 text-xs font-bold text-rose-700">Payment screenshot not submitted.</p>
                        <?php endif; ?>
                        <?php if ($userRole === 'customer' && strtolower($paymentStatus) !== 'confirmed'): ?>
                            <form method="POST" enctype="multipart/form-data" class="mt-3 space-y-2 no-print">
                                <input type="hidden" name="csrf_token" value="<?= h($orderCsrf) ?>">
                                <label for="payment-reference" class="block text-xs font-semibold text-stone-700">GCash reference number</label>
                                <div class="flex flex-wrap gap-2">
                                    <input id="payment-reference" name="reference_number" type="text" required maxlength="100"
                                           value="<?= h($order['reference_number'] ?? '') ?>" placeholder="Enter transfer reference"
                                           class="min-w-48 flex-1 rounded-lg border border-stone-300 px-3 py-2 text-xs focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                                    <input id="payment-proof" name="payment_proof" type="file" required accept="image/jpeg,image/png,image/webp"
                                           class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs">
                                    <button type="submit" name="submit_payment_reference" value="1" class="rounded-lg bg-emerald-800 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">Submit reference and screenshot</button>
                                </div>
                                <p class="text-xs text-stone-500">Both the reference and a JPG, PNG, or WEBP screenshot (up to 5 MB) are required. Staff must verify them before preparing the order.</p>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="font-bold text-stone-500 uppercase">Order Status</p>
                    <p class="font-bold text-amber-600 uppercase text-sm"><?= h($statusLabels[$orderStatus] ?? $orderStatus) ?></p>
                </div>
                <div>
                    <p class="font-bold text-stone-500 uppercase">Total Amount</p>
                    <p class="font-bold text-emerald-800 text-sm">₱<?= number_format($order['total_amount'] ?? 0, 2) ?></p>
                </div>
            </div>

            <?php if ($orderStatus === 'cancelled'): ?>
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">This order has been cancelled.</div>
            <?php else: ?>
                <section aria-label="Order progress" class="border-t border-stone-200 pt-6">
                    <h2 class="mb-5 text-sm font-extrabold uppercase tracking-wider text-emerald-950">Order Tracking</h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                        <?php foreach ($statusSteps as $stepIndex => $step): ?>
                            <?php $isComplete = $currentStep >= $stepIndex; ?>
                            <div class="flex items-center gap-3 sm:block">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 <?= $isComplete ? 'border-emerald-800 bg-emerald-800 text-white' : 'border-stone-300 bg-stone-100 text-stone-400' ?> font-extrabold">
                                    <?= $isComplete ? '&#10003;' : ($stepIndex + 1) ?>
                                </div>
                                <div class="sm:mt-2">
                                    <p class="text-xs font-bold <?= $isComplete ? 'text-emerald-900' : 'text-stone-500' ?>"><?= h($statusLabels[$step]) ?></p>
                                    <?php if ($step === $orderStatus): ?>
                                        <p class="mt-0.5 text-[11px] font-semibold text-amber-600">Current status</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="mt-6 border-t border-stone-200 pt-5" aria-label="Ordered items">
                <h2 class="mb-3 text-sm font-extrabold uppercase tracking-wider text-emerald-950">Ordered Items</h2>
                <div class="divide-y divide-stone-200">
                    <?php foreach ($orderItems as $item): ?>
                        <div class="flex justify-between gap-4 py-3 text-sm">
                            <span class="font-semibold text-stone-800">
                                <?= h($item['name']) ?> &times; <?= (int) $item['quantity'] ?>
                                <?php if ((float) $item['price'] === 0.0): ?>
                                    <span class="ml-2 inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-800">Free reward</span>
                                <?php endif; ?>
                                <?php if (($item['custom_cut'] ?? 'none') !== 'none'): ?>
                                    <span class="mt-1 block text-xs font-medium text-emerald-800">Requested cut: <?= h(halalMeatCutOptions()[$item['custom_cut']] ?? 'Custom cut') ?></span>
                                <?php endif; ?>
                                <?php
                                $selectedToppings = json_decode((string) ($item['custom_toppings'] ?? '[]'), true);
                                $toppingOptions = halalDishCustomizationOptions($item);
                                $selectedToppingLabels = is_array($selectedToppings)
                                    ? array_values(array_intersect_key($toppingOptions, array_flip($selectedToppings)))
                                    : [];
                                ?>
                                <?php if ($selectedToppingLabels): ?>
                                    <span class="mt-1 block text-xs font-medium text-emerald-800">Custom options: <?= h(implode(', ', $selectedToppingLabels)) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="shrink-0 font-medium">₱<?= number_format((float) $item['price'] * (int) $item['quantity'], 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <?php if ($isStaffOrAdmin): ?>
            <div class="bg-emerald-950 text-white p-6 rounded-2xl space-y-4 no-print">
                <h2 class="text-sm font-bold text-amber-400 uppercase tracking-wider">Staff Controls</h2>
                <div class="flex flex-wrap gap-3">
                    <form method="POST" class="flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?= h($orderCsrf) ?>">
                        <label for="status-select" class="sr-only">Update order status</label>
                        <select id="status-select" name="status" class="bg-emerald-900 border border-emerald-800 text-xs rounded-xl p-2 font-bold text-white">
                            <option value="pending"          <?= $orderStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="confirmed"        <?= $orderStatus === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                            <option value="preparing"        <?= $orderStatus === 'preparing' ? 'selected' : '' ?>>Preparing</option>
                            <option value="ready"            <?= $orderStatus === 'ready' ? 'selected' : '' ?>>Ready</option>
                            <option value="out for delivery" <?= $orderStatus === 'out for delivery' ? 'selected' : '' ?>>Out for Delivery</option>
                            <option value="completed"        <?= $orderStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled"        <?= $orderStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                        <button type="submit" name="update_status" value="1" class="bg-amber-500 text-emerald-950 px-3 py-2 rounded-xl text-xs font-extrabold">Confirm/Update Status</button>
                    </form>

                    <?php if (!in_array(strtolower($paymentStatus), ['paid', 'confirmed'], true) && ($paymentMethod !== 'GCash' || (!empty($order['reference_number']) && !empty($order['proof_path'])))): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= h($orderCsrf) ?>">
                            <button type="submit" name="confirm_payment" value="1" class="bg-emerald-700 hover:bg-emerald-600 text-white px-3 py-2 rounded-xl text-xs font-extrabold border border-emerald-600">Accept Payment</button>
                        </form>
                    <?php elseif ($paymentMethod === 'GCash' && strtolower($paymentStatus) !== 'paid'): ?>
                        <p class="text-xs text-amber-200">
                            <?php if (trim((string) ($order['reference_number'] ?? '')) !== '' && !empty($order['proof_path']) && is_file(__DIR__ . '/' . $order['proof_path'])): ?>
                                Reference and screenshot submitted. Review the screenshot above before accepting payment.
                            <?php else: ?>
                                Waiting for the customer to submit both the GCash reference and payment screenshot.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <form method="POST" class="pt-4 border-t border-emerald-900 space-y-2">
                    <input type="hidden" name="csrf_token" value="<?= h($orderCsrf) ?>">
                    <label for="notif_message" class="block text-xs font-bold text-stone-300">Send Notification to Customer</label>
                    <div class="flex gap-2">
                        <input id="notif_message" type="text" name="notif_message" placeholder="e.g. Your food is being prepared..." required class="flex-grow border border-emerald-800 rounded-xl p-2 text-xs text-stone-900 bg-white">
                        <button type="submit" name="send_notification" value="1" class="bg-amber-500 text-emerald-950 px-4 py-2 rounded-xl text-xs font-extrabold">Send</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>