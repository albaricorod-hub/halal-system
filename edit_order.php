<?php
require_once 'config.php';
checkAuth(['customer']);
ensureCustomerLoyaltyRewardsTable($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS order_history_archive (
    order_id INT NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order_history_archive_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$orderCsrf = csrfToken('order_history_csrf');
$userId    = (int) $_SESSION['user_id'];
$orderId   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error     = '';

if (!$orderId) {
    header('Location: order.php?notice=unavailable');
    exit;
}

$orderQuery = "SELECT o.id, o.total_amount,
                  CASE WHEN LOWER(o.order_status) = 'pending' AND LOWER(o.status) <> 'pending' THEN o.status
                      ELSE COALESCE(NULLIF(o.order_status, ''), NULLIF(o.status, ''), 'Pending') END AS current_status
               FROM orders o
               LEFT JOIN order_history_archive a ON a.order_id = o.id AND a.user_id = o.user_id
               WHERE o.id = ? AND o.user_id = ? AND a.order_id IS NULL";
$orderStmt = $pdo->prepare($orderQuery);
$orderStmt->execute([$orderId, $userId]);
$order = $orderStmt->fetch();

if (!$order || strtolower(trim((string) $order['current_status'])) !== 'pending') {
    header('Location: order.php?notice=unavailable');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('order_history_csrf');
    $quantities = $_POST['qty'] ?? null;

    if (!is_array($quantities)) {
        $error = 'The order changes could not be verified. Please try again.';
    } else {
        try {
            ensureSystemActivityTable($pdo);
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare("SELECT CASE WHEN LOWER(o.order_status) = 'pending' AND LOWER(o.status) <> 'pending' THEN o.status
                                                    ELSE COALESCE(NULLIF(o.order_status, ''), NULLIF(o.status, ''), 'Pending') END AS current_status
                                       FROM orders o
                                       LEFT JOIN order_history_archive a ON a.order_id = o.id AND a.user_id = o.user_id
                                       WHERE o.id = ? AND o.user_id = ? AND a.order_id IS NULL
                                       FOR UPDATE");
            $lockStmt->execute([$orderId, $userId]);
            $lockedOrder = $lockStmt->fetch();
            if (!$lockedOrder || strtolower(trim((string) $lockedOrder['current_status'])) !== 'pending') {
                throw new RuntimeException('This order can no longer be edited.');
            }

            $itemsStmt = $pdo->prepare('SELECT food_id, quantity, price FROM order_items WHERE order_id = ? FOR UPDATE');
            $itemsStmt->execute([$orderId]);
            $items = $itemsStmt->fetchAll();

            $itemIds        = [];
            $total          = 0;
            $remainingItems = 0;

            foreach ($items as $item) {
                $foodId   = (int) $item['food_id'];
                $isReward = ((float) $item['price'] === 0.0);

                $itemIds[$foodId] = true;

                if ($isReward) {
                    $total += 0;
                    $remainingItems++;
                    continue;
                }

                if (!array_key_exists($foodId, $quantities)) {
                    $quantity = (int) $item['quantity'];
                } else {
                    $quantity = filter_var($quantities[$foodId], FILTER_VALIDATE_INT);
                    if ($quantity === false || $quantity < 0) {
                        throw new DomainException('Enter a valid quantity for every item.');
                    }
                }

                if ($quantity === 0) {
                    $deleteItem = $pdo->prepare('DELETE FROM order_items WHERE order_id = ? AND food_id = ? AND price > 0');
                    $deleteItem->execute([$orderId, $foodId]);
                    continue;
                }

                $updateItem = $pdo->prepare('UPDATE order_items SET quantity = ? WHERE order_id = ? AND food_id = ? AND price > 0');
                $updateItem->execute([$quantity, $orderId, $foodId]);
                $total += (float) $item['price'] * $quantity;
                $remainingItems++;
            }

            foreach ($quantities as $foodId => $quantity) {
                if (!isset($itemIds[(int) $foodId])) {
                    throw new DomainException('The order contains an item that could not be verified.');
                }
            }

            if ($remainingItems === 0) {
                throw new DomainException('An order must contain at least one item.');
            }

            $paymentCheck = $pdo->prepare("SELECT payment_method, payment_status, reference_number, proof_path, amount FROM payments WHERE order_id = ? FOR UPDATE");
            $paymentCheck->execute([$orderId]);
            $existingPayment = $paymentCheck->fetch();

            $newTotal      = $total;
            $gcashReset    = false;

            if ($existingPayment
                && $existingPayment['payment_method'] === 'GCash'
                && (string) $existingPayment['reference_number'] !== ''
                && (string) $existingPayment['proof_path'] !== ''
                && (float) $existingPayment['amount'] !== (float) $newTotal) {

                $oldProofPath = (string) $existingPayment['proof_path'];
                $resetPayment = $pdo->prepare("UPDATE payments SET reference_number = NULL, proof_path = NULL, payment_status = 'Pending Verification', amount = ? WHERE order_id = ?");
                $resetPayment->execute([$newTotal, $orderId]);
                $pdo->prepare("UPDATE orders SET payment_status = 'unpaid' WHERE id = ?")->execute([$orderId]);
                if ($oldProofPath !== '' && is_file(__DIR__ . '/' . $oldProofPath)) {
                    @unlink(__DIR__ . '/' . $oldProofPath);
                }
                $gcashReset = true;
            } else {
                $updatePayment = $pdo->prepare('UPDATE payments SET amount = ? WHERE order_id = ?');
                $updatePayment->execute([$newTotal, $orderId]);
            }

            $updateOrder = $pdo->prepare('UPDATE orders SET total_amount = ? WHERE id = ? AND user_id = ?');
            $updateOrder->execute([$newTotal, $orderId, $userId]);

            recordSystemActivity($pdo, 'Order edited', "Customer edited pending order #ORD-{$orderId}; new total ₱" . number_format($newTotal, 2));

            if ($gcashReset) {
                createNotification(
                    $pdo,
                    $userId,
                    'Payment proof reset',
                    "Your order total for #ORD-{$orderId} changed. Please resubmit your GCash reference and screenshot."
                );
                notifyAdmins($pdo, "Order #ORD-{$orderId} was edited after GCash proof submission; payment proof was reset.");
                recordSystemActivity($pdo, 'GCash proof invalidated', "Order #ORD-{$orderId} total changed; GCash proof reset.");
            }

            $pdo->commit();

            header('Location: order.php?notice=edited' . ($gcashReset ? '&proof_reset=1' : ''));
            exit;
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Edit order failed: ' . $exception->getMessage());
            $error = 'Unable to save order changes. Please try again.';
        }
    }
}

$itemsStmt = $pdo->prepare('SELECT oi.food_id, oi.quantity, oi.price, f.name FROM order_items oi JOIN foods f ON f.id = oi.food_id WHERE oi.order_id = ? ORDER BY f.name');
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();
$foodImages = require 'food_images.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Order #<?= (int) $orderId ?> - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-stone-50 text-stone-800">
    <?php include 'navbar.php'; ?>
    <main class="max-w-4xl mx-auto px-4 py-8">
        <a href="order.php" class="text-sm font-semibold text-emerald-800 hover:text-emerald-600">&larr; Back to order history</a>
        <h1 class="mt-4 mb-6 text-3xl font-extrabold text-emerald-950">Edit Order #<?= (int) $orderId ?></h1>

        <?php if ($error !== ''): ?>
            <p role="alert" class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><?= h($error) ?></p>
        <?php endif; ?>

        <form method="POST" class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
            <input type="hidden" name="csrf_token" value="<?= h($orderCsrf) ?>">
            <div class="divide-y divide-stone-200">
                <?php foreach ($items as $item): ?>
                    <?php
                    $foodImage = $foodImages[$item['name']] ?? 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=300&q=80';
                    $isReward  = ((float) $item['price'] === 0.0);
                    ?>
                    <div class="flex flex-wrap items-center justify-between gap-4 p-4 <?= $isReward ? 'bg-emerald-50/50' : '' ?>">
                        <div class="flex min-w-0 items-center gap-3">
                            <img src="<?= h($foodImage) ?>" alt="<?= h($item['name']) ?>" class="h-16 w-16 shrink-0 rounded-lg object-cover" loading="lazy">
                            <div>
                                <p class="font-bold text-emerald-950"><?= h($item['name']) ?></p>
                                <p class="text-sm text-stone-500">
                                    <?= $isReward ? 'Free loyalty reward (locked)' : '₱' . number_format((float) $item['price'], 2) . ' each' ?>
                                </p>
                                <?php if ($isReward): ?>
                                    <span class="mt-1 inline-flex rounded-full bg-emerald-200 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-900">Reward item</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <label class="flex items-center gap-3 text-sm font-semibold" for="qty-<?= (int) $item['food_id'] ?>-<?= (int) $item['price'] ?>">
                            Quantity
                            <?php if ($isReward): ?>
                                <input id="qty-<?= (int) $item['food_id'] ?>-<?= (int) $item['price'] ?>" type="number" value="<?= (int) $item['quantity'] ?>" disabled
                                       class="w-20 rounded-lg border border-stone-300 bg-stone-100 p-2 text-center text-stone-500">
                            <?php else: ?>
                                <input id="qty-<?= (int) $item['food_id'] ?>-<?= (int) $item['price'] ?>" type="number" name="qty[<?= (int) $item['food_id'] ?>]" value="<?= (int) $item['quantity'] ?>" min="0" step="1"
                                       class="w-20 rounded-lg border border-stone-300 p-2 text-center">
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="flex flex-wrap justify-between gap-3 border-t border-stone-200 bg-stone-50 p-4">
                <a href="order.php" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-100">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-800 px-5 py-2 text-sm font-bold text-white hover:bg-emerald-700">Save Order Changes</button>
            </div>
        </form>
    </main>
</body>
</html>