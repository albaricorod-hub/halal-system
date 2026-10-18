<?php
require_once 'config.php';
checkAuth(['staff', 'admin']);
ensurePaymentProofColumn($pdo);
ensureCustomerLoyaltyRewardsTable($pdo);

$userRole = strtolower(trim((string) ($_SESSION['role'] ?? 'guest')));

if (!in_array($userRole, ['admin', 'staff'], true)) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff_dashboard.php');
    exit;
}

requireCsrf('order_csrf');

$orderId   = filter_var($_POST['order_id'] ?? 0, FILTER_VALIDATE_INT);
$newStatus = strtolower(trim((string) ($_POST['order_status'] ?? '')));

$statusValues = [
    'pending'          => ['Pending', 'pending'],
    'confirmed'        => ['Confirmed', 'pending'],
    'preparing'        => ['Preparing', 'preparing'],
    'ready'            => ['Ready', 'preparing'],
    'out for delivery' => ['Out for Delivery', 'preparing'],
    'completed'        => ['Completed', 'completed'],
    'cancelled'        => ['Cancelled', 'cancelled'],
];

if (!$orderId || !isset($statusValues[$newStatus])) {
    header('Location: staff_dashboard.php');
    exit;
}

$orderStmt = $pdo->prepare("SELECT o.user_id, COALESCE(NULLIF(p.payment_method, ''), NULLIF(o.payment_method, ''), 'COD') AS payment_method,
                                   COALESCE(NULLIF(p.payment_status, ''), NULLIF(o.payment_status, ''), 'unpaid') AS payment_status,
                                   p.reference_number, p.proof_path
                            FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.id = ?");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch();
if (!$order) {
    header('Location: staff_dashboard.php');
    exit;
}

$customerId = (int) $order['user_id'];

// GCash orders cannot be processed until proof is verified
if ($order['payment_method'] === 'GCash'
    && !in_array($newStatus, ['pending', 'cancelled'], true)
    && (!in_array(strtolower((string) $order['payment_status']), ['paid', 'confirmed'], true)
        || trim((string) ($order['reference_number'] ?? '')) === ''
        || empty($order['proof_path'])
        || !is_file(__DIR__ . '/' . $order['proof_path']))) {
    $_SESSION['payment_error'] = 'Verify the GCash reference and screenshot before processing this order.';
    header('Location: order_tracker.php?id=' . $orderId);
    exit;
}

try {
    ensureCustomerLoyaltyRewardsTable($pdo);
    ensureSystemActivityTable($pdo);
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE orders SET order_status = ?, status = ? WHERE id = ?')
        ->execute([$statusValues[$newStatus][0], $statusValues[$newStatus][1], $orderId]);

    if (in_array($newStatus, ['completed', 'cancelled'], true)) {
        try {
            $loyaltyStatus = refreshCustomerLoyalty($pdo, $customerId);
            foreach ($loyaltyStatus['earned_rewards'] as $reward) {
                createNotification(
                    $pdo,
                    $customerId,
                    'Free food reward unlocked',
                    "You earned a free {$reward['food_name']}. It will be automatically included with your next order."
                );
            }
        } catch (Throwable $e) {
            error_log('Loyalty refresh failed during status update: ' . $e->getMessage());
        }
    }

    if ($newStatus === 'cancelled') {
        $pdo->prepare("UPDATE customer_loyalty_rewards SET status = 'pending', order_id = NULL WHERE order_id = ? AND user_id = ? AND status = 'redeemed'")
            ->execute([$orderId, $customerId]);
    }

    createNotification($pdo, $customerId, 'Order status updated', "Order #ORD-{$orderId} is now {$statusValues[$newStatus][0]}.");
    recordSystemActivity($pdo, 'Order status updated', "Order #ORD-{$orderId} set to {$newStatus}.");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order status update failed: ' . $e->getMessage());
}

header('Location: order_tracker.php?id=' . $orderId);
exit;