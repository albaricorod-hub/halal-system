<?php
require_once 'config.php';
checkAuth();
ensureCustomerLoyaltyRewardsTable($pdo);

$userId   = (int) $_SESSION['user_id'];
$userRole = strtolower(trim((string) ($_SESSION['role'] ?? 'customer')));

$pdo->exec("CREATE TABLE IF NOT EXISTS order_history_archive (
    order_id INT NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order_history_archive_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$orderCsrf = csrfToken('order_history_csrf');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('order_history_csrf');
    $action = (string) ($_POST['action'] ?? '');

    if ($userRole !== 'customer') {
        header('Location: order.php?notice=invalid');
        exit;
    }

    if ($action === 'purge_selected') {
        $submittedIds = $_POST['order_ids'] ?? null;
        if (!is_array($submittedIds) || empty($submittedIds)) {
            header('Location: order.php?view=deleted&notice=no_selection');
            exit;
        }

        $orderIds = [];
        foreach ($submittedIds as $submittedId) {
            $validId = filter_var($submittedId, FILTER_VALIDATE_INT);
            if (!$validId || $validId < 1) {
                header('Location: order.php?view=deleted&notice=invalid');
                exit;
            }
            $orderIds[$validId] = $validId;
        }
        $orderIds     = array_values($orderIds);
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        try {
            ensureSystemActivityTable($pdo);
            $pdo->beginTransaction();

            $archivedStmt = $pdo->prepare("SELECT order_id FROM order_history_archive
                                           WHERE user_id = ? AND order_id IN ($placeholders) FOR UPDATE");
            $archivedStmt->execute(array_merge([$userId], $orderIds));
            $archivedIds = array_map('intval', $archivedStmt->fetchAll(PDO::FETCH_COLUMN));

            if (count($archivedIds) !== count($orderIds)) {
                throw new RuntimeException('One or more selected orders are not in your deleted history.');
            }

            // Release any loyalty rewards that were tied to these orders
            $releaseRewards = $pdo->prepare("UPDATE customer_loyalty_rewards SET status = 'pending', order_id = NULL WHERE user_id = ? AND order_id IN ($placeholders) AND status = 'redeemed'");
            $releaseRewards->execute(array_merge([$userId], $orderIds));

            $deleteItems   = $pdo->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)");
            $deleteItems->execute($orderIds);
            $deletePayments = $pdo->prepare("DELETE FROM payments WHERE order_id IN ($placeholders)");
            $deletePayments->execute($orderIds);
            $deleteOrders  = $pdo->prepare("DELETE FROM orders WHERE user_id = ? AND id IN ($placeholders)");
            $deleteOrders->execute(array_merge([$userId], $orderIds));
            $deleteArchive = $pdo->prepare("DELETE FROM order_history_archive WHERE user_id = ? AND order_id IN ($placeholders)");
            $deleteArchive->execute(array_merge([$userId], $orderIds));

            recordSystemActivity($pdo, 'Archived orders permanently deleted', 'Customer permanently removed archived orders: ' . implode(', ', $orderIds));
            $pdo->commit();

            // Recompute loyalty after purge
            try {
                refreshCustomerLoyalty($pdo, $userId);
            } catch (Throwable $e) {
                error_log('Post-purge loyalty refresh failed: ' . $e->getMessage());
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Purge failed: ' . $exception->getMessage());
            header('Location: order.php?view=deleted&notice=delete_failed');
            exit;
        }

        header('Location: order.php?view=deleted&notice=purged');
        exit;
    }

    $orderId = filter_var($_POST['order_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$orderId) {
        header('Location: order.php?notice=invalid');
        exit;
    }

    if ($action === 'restore') {
        $restore = $pdo->prepare('DELETE FROM order_history_archive WHERE order_id = ? AND user_id = ?');
        $restore->execute([$orderId, $userId]);
        if ($restore->rowCount()) {
            recordSystemActivity($pdo, 'Order restored', "Customer restored order #ORD-{$orderId}.");
        }
        $notice = $restore->rowCount() ? 'restored' : 'unavailable';
        header('Location: order.php?notice=' . $notice);
        exit;
    }

    if ($action === 'archive') {
        $orderStmt = $pdo->prepare("SELECT CASE WHEN LOWER(order_status) = 'pending' AND LOWER(status) <> 'pending' THEN status
                                    ELSE COALESCE(NULLIF(order_status, ''), NULLIF(status, ''), 'Pending') END AS current_status
                                    FROM orders WHERE id = ? AND user_id = ?");
        $orderStmt->execute([$orderId, $userId]);
        $order = $orderStmt->fetch();

        if ($order && strtolower(trim($order['current_status'])) === 'pending') {
            $archive = $pdo->prepare('INSERT INTO order_history_archive (order_id, user_id) VALUES (?, ?)');
            $archive->execute([$orderId, $userId]);
            recordSystemActivity($pdo, 'Order archived', "Customer moved pending order #ORD-{$orderId} to deleted history.");
            header('Location: order.php?notice=deleted');
        } else {
            header('Location: order.php?notice=unavailable');
        }
        exit;
    }

    header('Location: order.php?notice=invalid');
    exit;
}

$showDeleted = $userRole === 'customer' && ($_GET['view'] ?? '') === 'deleted';
$noticeMessages = [
    'deleted'       => 'Order moved to deleted history. You can restore it any time.',
    'restored'      => 'Order restored to your active history.',
    'edited'        => 'Order changes saved successfully.',
    'purged'        => 'Selected deleted orders were permanently removed.',
    'delete_failed' => 'The selected orders could not be permanently deleted.',
    'no_selection'  => 'Select at least one deleted order first.',
    'unavailable'   => 'That order is no longer available for this action.',
    'invalid'       => 'The order action could not be verified. Please try again.',
];
$notice = $noticeMessages[$_GET['notice'] ?? ''] ?? '';
if (isset($_GET['proof_reset']) && ($_GET['proof_reset'] ?? '') === '1') {
    $notice .= ' Your GCash payment proof was reset because the total changed. Please resubmit it.';
}

$query = "SELECT o.*, u.name AS customer_name, p.payment_method, p.payment_status,
              CASE WHEN LOWER(o.order_status) = 'pending' AND LOWER(o.status) <> 'pending' THEN o.status
                  ELSE COALESCE(NULLIF(o.order_status, ''), NULLIF(o.status, ''), 'Pending') END AS current_status
          FROM orders o
          LEFT JOIN users u ON o.user_id = u.id
          LEFT JOIN payments p ON o.id = p.order_id
          LEFT JOIN order_history_archive a ON a.order_id = o.id AND a.user_id = o.user_id";
$conditions = [$showDeleted ? 'a.order_id IS NOT NULL' : 'a.order_id IS NULL'];
$params     = [];
if (!in_array($userRole, ['admin', 'staff'], true)) {
    $conditions[] = 'o.user_id = ?';
    $params[]     = $userId;
}
$query .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY o.id DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: linear-gradient(180deg, #eef5f1 0%, #f8fbf9 100%); }
        .hh-surface { border: 1px solid rgba(22,59,50,.1); box-shadow: 0 18px 40px rgba(22,59,50,.07); }
    </style>
</head>
<body class="text-stone-800 min-h-screen">
    <?php include 'navbar.php'; ?>

    <main class="max-w-5xl mx-auto px-4 py-8">
        <p class="text-xs uppercase tracking-[0.2em] text-amber-700 font-bold">Order history</p>
        <h1 class="text-3xl sm:text-4xl font-extrabold text-emerald-950 mt-2 mb-7">
            <?= in_array($userRole, ['admin', 'staff'], true) ? 'All Customer Orders' : ($showDeleted ? 'Deleted Order History' : 'My Orders & Deliveries') ?>
        </h1>

        <?php if ($userRole === 'customer'): ?>
            <div class="flex gap-2 mb-5">
                <a href="order.php" class="px-4 py-2 rounded-lg text-sm font-semibold <?= !$showDeleted ? 'bg-emerald-800 text-white' : 'bg-white text-stone-700 border border-stone-300' ?>">Active orders</a>
                <a href="order.php?view=deleted" class="px-4 py-2 rounded-lg text-sm font-semibold <?= $showDeleted ? 'bg-emerald-800 text-white' : 'bg-white text-stone-700 border border-stone-300' ?>">Deleted history</a>
            </div>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
            <p role="status" class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"><?= h($notice) ?></p>
        <?php endif; ?>

        <?php if (empty($orders)): ?>
            <div class="hh-surface bg-white p-8 rounded-2xl text-center">
                <p class="text-stone-500 mb-4"><?= $showDeleted ? 'No deleted orders to restore.' : 'No order records found.' ?></p>
                <?php if (!$showDeleted): ?>
                    <a href="menu.php" class="bg-emerald-800 text-white px-4 py-2 rounded-lg font-semibold hover:bg-emerald-700">Browse Menu & Order Now &rarr;</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($showDeleted): ?>
                <form id="purge-orders-form" method="POST"
                      onsubmit="return confirm('Permanently delete the selected orders and their payment records? This cannot be undone.');"
                      class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-200 bg-white p-4">
                    <input type="hidden" name="csrf_token" value="<?= h($orderCsrf) ?>">
                    <input type="hidden" name="action" value="purge_selected">
                    <label for="select-all-orders" class="flex items-center gap-2 text-sm font-semibold text-stone-700">
                        <input id="select-all-orders" type="checkbox" class="h-4 w-4 accent-emerald-800"> Select all
                    </label>
                    <button type="submit" class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-bold text-white hover:bg-rose-600">Delete selected</button>
                </form>
            <?php endif; ?>

            <div class="hh-surface bg-white rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[700px] text-left text-sm">
                        <thead class="bg-emerald-900 text-white">
                            <tr>
                                <?php if ($showDeleted): ?><th class="p-3"><span class="sr-only">Select</span></th><?php endif; ?>
                                <th class="p-3">Order #</th>
                                <?php if (in_array($userRole, ['admin', 'staff'], true)): ?>
                                    <th class="p-3">Customer</th>
                                <?php endif; ?>
                                <th class="p-3">Date</th>
                                <th class="p-3">Total</th>
                                <th class="p-3">Payment</th>
                                <th class="p-3">Status</th>
                                <th class="p-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($orders as $o): ?>
                                <?php
                                $orderStatus  = strtolower(trim((string) ($o['current_status'] ?? 'pending')));
                                $paymentCode  = (string) ($o['payment_method'] ?? 'COD');
                                $paymentLabel = in_array($paymentCode, ['COD', 'Cash on Delivery'], true) ? 'Cash on Delivery' : $paymentCode;
                                ?>
                                <tr class="hover:bg-stone-50">
                                    <?php if ($showDeleted): ?>
                                        <td class="p-3">
                                            <input type="checkbox" name="order_ids[]" value="<?= (int) $o['id'] ?>" form="purge-orders-form"
                                                   class="deleted-order-checkbox h-4 w-4 accent-rose-700"
                                                   aria-label="Select order <?= (int) $o['id'] ?> for permanent deletion">
                                        </td>
                                    <?php endif; ?>
                                    <td class="p-3 font-bold text-emerald-950">#<?= (int) $o['id'] ?></td>
                                    <?php if (in_array($userRole, ['admin', 'staff'], true)): ?>
                                        <td class="p-3"><?= h($o['customer_name'] ?? 'Customer') ?></td>
                                    <?php endif; ?>
                                    <td class="p-3 text-xs text-stone-500"><?= date('M j, Y g:i A', strtotime($o['created_at'])) ?></td>
                                    <td class="p-3 font-bold">₱<?= number_format((float) $o['total_amount'], 2) ?></td>
                                    <td class="p-3 text-xs">
                                        <span class="block font-semibold"><?= h($paymentLabel) ?></span>
                                        <span class="text-[10px] text-stone-500"><?= h($o['payment_status'] ?? 'unpaid') ?></span>
                                    </td>
                                    <td class="p-3">
                                        <span class="px-2 py-0.5 rounded text-xs font-bold
                                            <?= $orderStatus === 'completed'        ? 'bg-emerald-100 text-emerald-800' : '' ?>
                                            <?= $orderStatus === 'out for delivery' ? 'bg-blue-100 text-blue-800'       : '' ?>
                                            <?= $orderStatus === 'preparing'        ? 'bg-amber-100 text-amber-800'      : '' ?>
                                            <?= $orderStatus === 'pending'          ? 'bg-stone-100 text-stone-800'      : '' ?>
                                            <?= $orderStatus === 'cancelled'        ? 'bg-red-100 text-red-800'          : '' ?>
                                        ">
                                            <?= h($o['current_status'] ?? 'Pending') ?>
                                        </span>
                                    </td>
                                    <td class="p-3 text-right">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <a href="order_tracker.php?id=<?= (int) $o['id'] ?>" class="bg-emerald-800 text-white text-xs px-3 py-1.5 rounded font-bold hover:bg-emerald-700 inline-flex items-center gap-1">Track Order</a>
                                            <?php if ($userRole === 'customer' && $showDeleted): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= h($orderCsrf) ?>">
                                                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                                    <input type="hidden" name="action" value="restore">
                                                    <button type="submit" class="bg-amber-500 text-emerald-950 text-xs px-3 py-1.5 rounded font-bold hover:bg-amber-400">Restore</button>
                                                </form>
                                            <?php elseif ($userRole === 'customer' && $orderStatus === 'pending'): ?>
                                                <a href="edit_order.php?id=<?= (int) $o['id'] ?>" class="bg-stone-700 text-white text-xs px-3 py-1.5 rounded font-bold hover:bg-stone-600">Edit</a>
                                                <form method="POST" class="inline" onsubmit="return confirm('Move this pending order to deleted history?');">
                                                    <input type="hidden" name="csrf_token" value="<?= h($orderCsrf) ?>">
                                                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                                    <input type="hidden" name="action" value="archive">
                                                    <button type="submit" class="bg-rose-700 text-white text-xs px-3 py-1.5 rounded font-bold hover:bg-rose-600">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <?php if ($showDeleted): ?>
        <script>
            const selectAllOrders = document.getElementById('select-all-orders');
            if (selectAllOrders) {
                const orderCheckboxes = document.querySelectorAll('.deleted-order-checkbox');
                selectAllOrders.addEventListener('change', () => {
                    orderCheckboxes.forEach(checkbox => { checkbox.checked = selectAllOrders.checked; });
                });
                orderCheckboxes.forEach(checkbox => checkbox.addEventListener('change', () => {
                    const selectedCount = document.querySelectorAll('.deleted-order-checkbox:checked').length;
                    selectAllOrders.checked = selectedCount === orderCheckboxes.length;
                    selectAllOrders.indeterminate = selectedCount > 0 && selectedCount < orderCheckboxes.length;
                }));
            }
        </script>
    <?php endif; ?>
</body>
</html>