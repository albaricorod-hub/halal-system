<?php
require_once 'config.php';
checkAuth(['customer']);
ensureOrderItemCustomizationColumns($pdo);
ensurePaymentProofColumn($pdo);
ensureCustomerLoyaltyRewardsTable($pdo);

requireCsrf('cart_csrf');
$cartError = '';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
if (!isset($_SESSION['cart_cuts']) || !is_array($_SESSION['cart_cuts'])) {
    $_SESSION['cart_cuts'] = [];
}
if (!isset($_SESSION['cart_toppings']) || !is_array($_SESSION['cart_toppings'])) {
    $_SESSION['cart_toppings'] = [];
}

/* ------------------------------------------------------------------ */
/* Remove a single item                                                */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_item'])) {
    $removeFoodId = filter_var($_POST['remove_item'], FILTER_VALIDATE_INT);
    if (!$removeFoodId || !isset($_SESSION['cart'][$removeFoodId])) {
        $cartError = 'The selected order item could not be found in your cart.';
    } else {
        unset($_SESSION['cart'][$removeFoodId], $_SESSION['cart_cuts'][$removeFoodId], $_SESSION['cart_toppings'][$removeFoodId]);
        header('Location: cart.php');
        exit;
    }
}

/* ------------------------------------------------------------------ */
/* Update quantities / customisations                                  */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['remove_item']) && !isset($_POST['place_order'])
    && (isset($_POST['qty']) || isset($_POST['custom_cut']) || isset($_POST['custom_toppings_present']))) {

    $cutOptions        = halalMeatCutOptions();
    $submittedCuts     = $_POST['custom_cut'] ?? [];
    $submittedToppings = $_POST['custom_toppings'] ?? [];
    $toppingRows       = $_POST['custom_toppings_present'] ?? [];

    if (!is_array($submittedCuts) || !is_array($submittedToppings) || !is_array($toppingRows)) {
        $cartError = 'Invalid customization selection.';
    } else {
        $foodStmt = $pdo->prepare('SELECT f.name, f.ingredients, c.name AS category_name FROM foods f LEFT JOIN food_categories c ON c.id = f.category_id WHERE f.id = ?');

        foreach ($submittedCuts as $foodId => $selectedCut) {
            $foodId = filter_var($foodId, FILTER_VALIDATE_INT);
            if (!$foodId || !isset($_SESSION['cart'][$foodId]) || !is_string($selectedCut) || !array_key_exists($selectedCut, $cutOptions)) {
                $cartError = 'One or more meat cut selections could not be verified.';
                break;
            }
            $foodStmt->execute([$foodId]);
            $food = $foodStmt->fetch();
            if (!$food || ($selectedCut !== 'none' && !foodSupportsCustomMeatCuts($food))) {
                $cartError = 'Custom cuts are only available for eligible meat dishes.';
                break;
            }
        }

        if ($cartError === '') {
            foreach ($toppingRows as $foodId => $present) {
                $foodId = filter_var($foodId, FILTER_VALIDATE_INT);
                $selectedForFood = $submittedToppings[$foodId] ?? [];
                if (!$foodId || !isset($_SESSION['cart'][$foodId]) || $present !== '1' || !is_array($selectedForFood)) {
                    $cartError = 'One or more dessert topping selections could not be verified.';
                    break;
                }
                $foodStmt->execute([$foodId]);
                $food = $foodStmt->fetch();
                $availableToppings = $food ? halalDishCustomizationOptions($food) : [];
                foreach ($selectedForFood as $topping) {
                    if (!is_string($topping) || !array_key_exists($topping, $availableToppings)) {
                        $cartError = 'Choose valid toppings for each dessert.';
                        break 2;
                    }
                }
            }
            foreach ($submittedToppings as $foodId => $selectedForFood) {
                if (!isset($toppingRows[$foodId])) {
                    $cartError = 'One or more dessert topping selections could not be verified.';
                    break;
                }
            }
        }
    }

    if ($cartError === '') {
        foreach ($submittedCuts as $foodId => $selectedCut) {
            $_SESSION['cart_cuts'][(int) $foodId] = $selectedCut;
        }
        foreach ($toppingRows as $foodId => $present) {
            $_SESSION['cart_toppings'][(int) $foodId] = array_values(array_unique($submittedToppings[$foodId] ?? []));
        }

        $submittedQty = $_POST['qty'] ?? [];
        if (is_array($submittedQty)) {
            foreach ($submittedQty as $foodId => $rawQty) {
                $foodId = (int) $foodId;
                if ($rawQty === '' || $rawQty === null) {
                    continue;
                }
                $quantity = filter_var($rawQty, FILTER_VALIDATE_INT);
                if ($quantity === false) {
                    continue;
                }
                if ($quantity <= 0) {
                    unset($_SESSION['cart'][$foodId], $_SESSION['cart_cuts'][$foodId], $_SESSION['cart_toppings'][$foodId]);
                } elseif (isset($_SESSION['cart'][$foodId])) {
                    $_SESSION['cart'][$foodId] = $quantity;
                }
            }
        }
        header('Location: cart.php');
        exit;
    }
}

/* ------------------------------------------------------------------ */
/* Checkout                                                            */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    if (!empty($_SESSION['cart'])) {
        $userId            = (int) $_SESSION['user_id'];
        $paymentMethod     = (string) ($_POST['payment_method'] ?? 'Cash on Delivery');
        $referenceNumber   = is_string($_POST['reference_number'] ?? null) ? trim($_POST['reference_number']) : '';
        $cartIds           = array_values(array_unique(array_map('intval', array_keys($_SESSION['cart']))));
        $paymentProofPath  = null;
        $dbPaymentMethod   = null;

        if ($paymentMethod === 'GCash') {
            $dbPaymentMethod = 'GCash';
        } elseif ($paymentMethod === 'Cash on Delivery' || $paymentMethod === 'COD') {
            $dbPaymentMethod = 'COD';
        } else {
            $cartError = 'Invalid payment method selected.';
        }

        if ($cartError === '' && $dbPaymentMethod === 'GCash' && ($referenceNumber === '' || strlen($referenceNumber) > 100)) {
            $cartError = 'A valid GCash reference number is required before placing the order.';
        }
        if ($cartError === '' && $dbPaymentMethod === 'GCash' && ($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $cartError = 'Upload your GCash payment screenshot before placing the order.';
        }

        foreach ($_SESSION['cart'] as $quantity) {
            if (!filter_var($quantity, FILTER_VALIDATE_INT) || $quantity < 1) {
                $cartError = 'Every item must have a valid quantity.';
                break;
            }
        }

        if ($cartError === '' && !empty($cartIds)) {
            $inClause = implode(',', array_fill(0, count($cartIds), '?'));
            $stmt = $pdo->prepare("SELECT f.id, f.price, f.name, f.ingredients, c.name AS category_name
                                   FROM foods f LEFT JOIN food_categories c ON c.id = f.category_id
                                   WHERE f.id IN ($inClause) AND f.status = 'approved'");
            $stmt->execute($cartIds);
            $availableFoods = $stmt->fetchAll(PDO::FETCH_UNIQUE);
            $prices = array_map(static fn($food) => $food['price'], $availableFoods);

            if (count($prices) !== count($cartIds)) {
                $cartError = 'One or more dishes are no longer available. Please review your cart.';
            } else {
                foreach ($_SESSION['cart'] as $foodId => $quantity) {
                    $selectedToppings = $_SESSION['cart_toppings'][$foodId] ?? [];
                    $availableToppings = halalDishCustomizationOptions($availableFoods[$foodId]);
                    if (!is_array($selectedToppings)) {
                        $cartError = 'A dessert topping selection could not be verified. Please review your cart.';
                        break;
                    }
                    foreach ($selectedToppings as $topping) {
                        if (!is_string($topping) || !array_key_exists($topping, $availableToppings)) {
                            $cartError = 'A dessert topping selection could not be verified. Please review your cart.';
                            break 2;
                        }
                    }
                }
            }

            if ($cartError === '') {
                $addressStmt = $pdo->prepare("SELECT address FROM users WHERE id = ? AND LOWER(role) = 'customer' LIMIT 1");
                $addressStmt->execute([$userId]);
                $deliveryAddress = (string) ($addressStmt->fetchColumn() ?: '');

                $totalAmount = 0;
                foreach ($_SESSION['cart'] as $foodId => $quantity) {
                    $totalAmount += (float) $prices[$foodId] * (int) $quantity;
                }

                try {
                    if ($dbPaymentMethod === 'GCash') {
                        $paymentProofPath = storeGcashPaymentProof($_FILES['payment_proof']);
                    }
                    ensureSystemActivityTable($pdo);
                    $pdo->beginTransaction();

                    $orderStmt = $pdo->prepare("INSERT INTO orders (user_id, total_amount, order_status, delivery_address, status, payment_method, payment_status) VALUES (?, ?, 'Pending', ?, 'pending', ?, 'unpaid')");
                    $orderStmt->execute([$userId, $totalAmount, $deliveryAddress, $dbPaymentMethod]);
                    $orderId = (int) $pdo->lastInsertId();

                    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, food_id, quantity, price, custom_cut, custom_toppings) VALUES (?, ?, ?, ?, ?, ?)');
                    foreach ($_SESSION['cart'] as $foodId => $quantity) {
                        $customCut = $_SESSION['cart_cuts'][$foodId] ?? 'none';
                        if (!array_key_exists($customCut, halalMeatCutOptions())) {
                            throw new RuntimeException('An invalid meat cut selection was found in the cart.');
                        }
                        if ($customCut !== 'none' && !foodSupportsCustomMeatCuts($availableFoods[$foodId])) {
                            throw new RuntimeException('A custom meat cut was selected for an ineligible dish.');
                        }
                        $customToppings = $_SESSION['cart_toppings'][$foodId] ?? [];
                        $itemStmt->execute([$orderId, (int) $foodId, (int) $quantity, $prices[$foodId], $customCut, json_encode($customToppings, JSON_THROW_ON_ERROR)]);
                    }

                    $pendingRewardsStmt = $pdo->prepare("SELECT id, food_name FROM customer_loyalty_rewards WHERE user_id = ? AND status = 'pending' ORDER BY id FOR UPDATE");
                    $pendingRewardsStmt->execute([$userId]);
                    $availableRewards = $pendingRewardsStmt->fetchAll();
                    $rewardFoodStmt = $pdo->prepare("SELECT id FROM foods WHERE name = ? AND status = 'approved' LIMIT 1");
                    $redeemRewardStmt = $pdo->prepare("UPDATE customer_loyalty_rewards SET status = 'redeemed', order_id = ? WHERE id = ? AND user_id = ? AND status = 'pending'");
                    foreach ($availableRewards as $reward) {
                        $rewardFoodStmt->execute([$reward['food_name']]);
                        $rewardFoodId = $rewardFoodStmt->fetchColumn();
                        if (!$rewardFoodId) {
                            continue;
                        }
                        $itemStmt->execute([$orderId, (int) $rewardFoodId, 1, 0, 'none', '[]']);
                        $redeemRewardStmt->execute([$orderId, (int) $reward['id'], $userId]);
                    }

                    $initialPaymentStatus = 'Pending Verification';
                    $paymentStmt = $pdo->prepare("INSERT INTO payments (order_id, payment_method, payment_status, reference_number, amount, proof_path) VALUES (?, ?, ?, ?, ?, ?)");
                    $paymentStmt->execute([
                        $orderId,
                        $dbPaymentMethod,
                        $initialPaymentStatus,
                        $dbPaymentMethod === 'GCash' ? $referenceNumber : null,
                        $totalAmount,
                        $paymentProofPath,
                    ]);

                    createNotification($pdo, $userId, 'Order received', "Order #ORD-{$orderId} was submitted and is awaiting confirmation.");
                    notifyAdmins($pdo, "New customer order #ORD-{$orderId} is awaiting confirmation.");
                    recordSystemActivity($pdo, 'Order submitted', "Order #ORD-{$orderId}; total ₱" . number_format($totalAmount, 2));
                    $pdo->commit();

                    unset($_SESSION['cart'], $_SESSION['cart_cuts'], $_SESSION['cart_toppings']);
                    header('Location: order_tracker.php?id=' . $orderId);
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($paymentProofPath !== null && is_file(__DIR__ . '/' . $paymentProofPath)) {
                        @unlink(__DIR__ . '/' . $paymentProofPath);
                    }
                    error_log('Checkout failed: ' . $exception->getMessage());
                    $cartError = $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'Unable to submit the order. Please review your cart and try again.';
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Load cart view                                                      */
/* ------------------------------------------------------------------ */
$cartItems               = [];
$total                   = 0;
$hasUnavailableItems     = false;
$availableLoyaltyRewards = [];

$rewardsStmt = $pdo->prepare("SELECT tier, food_name FROM customer_loyalty_rewards WHERE user_id = ? AND status = 'pending' ORDER BY id");
$rewardsStmt->execute([(int) $_SESSION['user_id']]);
$availableLoyaltyRewards = $rewardsStmt->fetchAll();

$foodImages = require 'food_images.php';

if (!empty($_SESSION['cart'])) {
    $cartIds  = array_values(array_unique(array_map('intval', array_keys($_SESSION['cart']))));
    $inClause = implode(',', array_fill(0, count($cartIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM foods WHERE id IN ($inClause)");
    $stmt->execute($cartIds);
    $rawItems = $stmt->fetchAll();
    $itemsById = [];
    foreach ($rawItems as $raw) {
        $itemsById[(int) $raw['id']] = $raw;
    }
    foreach ($cartIds as $foodId) {
        if (!isset($itemsById[$foodId])) {
            unset($_SESSION['cart'][$foodId], $_SESSION['cart_cuts'][$foodId], $_SESSION['cart_toppings'][$foodId]);
            continue;
        }
        $item = $itemsById[$foodId];
        $item['is_available'] = ($item['status'] === 'approved');
        if (!$item['is_available']) {
            $hasUnavailableItems = true;
        } else {
            $total += (float) $item['price'] * (int) $_SESSION['cart'][$foodId];
        }
        $cartItems[] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: linear-gradient(180deg, #eef5f1 0%, #f8fbf9 100%); }
        .hh-surface { border: 1px solid rgba(22,59,50,.1); box-shadow: 0 18px 40px rgba(22,59,50,.07); }
    </style>
</head>
<body class="text-stone-800 min-h-screen">
    <?php include 'navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="mb-7">
            <p class="text-xs uppercase tracking-[0.2em] text-amber-700 font-bold">Your basket</p>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-emerald-950 mt-2">Your halal order cart</h1>
        </div>

        <?php if ($cartError !== ''): ?>
            <p role="alert" class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><?= h($cartError) ?></p>
        <?php endif; ?>

        <?php if ($hasUnavailableItems): ?>
            <p role="alert" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                One or more items in your cart are no longer available. Remove them before checking out.
            </p>
        <?php endif; ?>

        <?php if (empty($cartItems)): ?>
            <div class="hh-surface bg-white p-8 rounded-2xl text-center">
                <p class="text-stone-500 mb-4">Your order cart is currently empty.</p>
                <a href="menu.php" class="bg-emerald-800 text-white px-4 py-2 rounded-lg font-semibold hover:bg-emerald-700">Browse Food Choices &rarr;</a>
            </div>
        <?php else: ?>
            <form method="POST" class="hh-surface bg-white rounded-2xl overflow-hidden mb-6">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken('cart_csrf')) ?>">
                <table class="w-full text-left text-sm">
                    <thead class="bg-emerald-900 text-white">
                        <tr>
                            <th class="p-3">Dish</th>
                            <th class="p-3">Price</th>
                            <th class="p-3">Quantity</th>
                            <th class="p-3">Customizations</th>
                            <th class="p-3">Subtotal</th>
                            <th class="p-3">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($cartItems as $item): ?>
                            <?php
                            $qty      = (int) $_SESSION['cart'][$item['id']];
                            $subtotal = (float) $item['price'] * $qty;
                            $foodImage = $foodImages[$item['name']] ?? 'https://images.unsplash.com/photo-1547592180-85f173990554?auto=format&fit=crop&w=300&q=80';
                            $isAvailable = $item['is_available'];
                            ?>
                            <tr class="<?= $isAvailable ? '' : 'bg-amber-50/50' ?>">
                                <td class="p-3">
                                    <div class="flex items-center gap-3 min-w-52">
                                        <img src="<?= h($foodImage) ?>" alt="<?= h($item['name']) ?>" class="w-16 h-16 rounded-lg object-cover shrink-0" loading="lazy">
                                        <div>
                                            <span class="font-semibold text-emerald-950 block"><?= h($item['name']) ?></span>
                                            <?php if (!$isAvailable): ?>
                                                <span class="mt-1 inline-flex rounded-full bg-amber-200 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-900">No longer available</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3">₱<?= number_format($item['price'], 2) ?></td>
                                <td class="p-3">
                                    <?php if ($isAvailable): ?>
                                        <input type="number" name="qty[<?= (int) $item['id'] ?>]" value="<?= $qty ?>" min="0"
                                               class="w-16 border rounded p-1 text-center" onchange="this.form.requestSubmit()"
                                               aria-label="Quantity for <?= h($item['name']) ?>">
                                    <?php else: ?>
                                        <span class="text-stone-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3">
                                    <?php if ($isAvailable): ?>
                                        <?php if (foodSupportsCustomMeatCuts($item)): ?>
                                            <label class="mb-1 block text-xs font-semibold text-stone-600" for="cut-<?= (int) $item['id'] ?>">Meat cut</label>
                                            <select id="cut-<?= (int) $item['id'] ?>" name="custom_cut[<?= (int) $item['id'] ?>]"
                                                    class="mb-3 w-full min-w-36 rounded-lg border border-stone-300 bg-white p-2 text-xs"
                                                    onchange="this.form.requestSubmit()" aria-label="Meat cut for <?= h($item['name']) ?>">
                                                <?php foreach (halalMeatCutOptions() as $cutValue => $cutLabel): ?>
                                                    <option value="<?= h($cutValue) ?>" <?= (($_SESSION['cart_cuts'][$item['id']] ?? 'none') === $cutValue) ? 'selected' : '' ?>><?= h($cutLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                        <?php $customizationOptions = halalDishCustomizationOptions($item); ?>
                                        <input type="hidden" name="custom_toppings_present[<?= (int) $item['id'] ?>]" value="1">
                                        <div class="min-w-40 space-y-1.5">
                                            <?php foreach ($customizationOptions as $optionValue => $optionLabel): ?>
                                                <label class="flex items-center gap-1.5 text-xs">
                                                    <input type="checkbox" name="custom_toppings[<?= (int) $item['id'] ?>][]" value="<?= h($optionValue) ?>"
                                                        <?= is_array($_SESSION['cart_toppings'][$item['id']] ?? null) && in_array($optionValue, $_SESSION['cart_toppings'][$item['id']], true) ? 'checked' : '' ?>
                                                        onchange="this.form.requestSubmit()" aria-label="<?= h($optionLabel . ' for ' . $item['name']) ?>">
                                                    <span><?= h($optionLabel) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-stone-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 font-bold text-emerald-900">
                                    <?= $isAvailable ? '₱' . number_format($subtotal, 2) : '—' ?>
                                </td>
                                <td class="p-3">
                                    <button type="submit" name="remove_item" value="<?= (int) $item['id'] ?>"
                                            class="whitespace-nowrap rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-700 transition hover:bg-red-50"
                                            aria-label="Remove <?= h($item['name']) ?> from cart">Remove order</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="p-4 bg-stone-50 border-t flex justify-between items-center">
                    <span class="text-xl font-bold text-emerald-950">Total: ₱<?= number_format($total, 2) ?></span>
                </div>
            </form>

            <div class="hh-surface bg-white p-6 rounded-2xl">
                <h3 class="text-lg font-bold text-emerald-950 mb-3">Checkout Details</h3>

                <?php if (!empty($availableLoyaltyRewards)): ?>
                    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <p class="text-sm font-bold text-emerald-950">Free food rewards will be included</p>
                        <p class="mt-1 text-xs text-stone-600">Your available rewards are automatically added to this order at no charge.</p>
                        <ul class="mt-2 space-y-1 text-xs font-semibold text-stone-700">
                            <?php foreach ($availableLoyaltyRewards as $reward): ?>
                                <li><?= h($reward['food_name']) ?> <span class="text-emerald-800">(<?= h($reward['tier']) ?> reward)</span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- enctype removed from markup; toggled via JS to prevent InfinityFree WAF 403 on empty file parts -->
                <form method="POST" id="cart-checkout-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken('cart_csrf')) ?>">
                    <fieldset class="mb-4">
                        <legend class="mb-2 block text-sm font-semibold text-stone-700">Select Payment Method</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 transition has-[:checked]:border-emerald-700 has-[:checked]:ring-2 has-[:checked]:ring-emerald-100">
                                <input type="radio" name="payment_method" value="Cash on Delivery" class="mt-1 accent-emerald-800" checked>
                                <span class="flex gap-3">
                                    <svg class="h-7 w-7 shrink-0 text-emerald-800" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7.5A2.5 2.5 0 015.5 5h13A2.5 2.5 0 0121 7.5v9a2.5 2.5 0 01-2.5 2.5h-13A2.5 2.5 0 013 16.5v-9zM3 9h18m-13 5h4"></path></svg>
                                    <span><strong class="block text-sm text-emerald-950">Cash on Delivery</strong><span class="mt-1 block text-xs text-stone-600">Pay in cash when your order arrives.</span></span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 transition has-[:checked]:border-blue-600 has-[:checked]:ring-2 has-[:checked]:ring-blue-100">
                                <input type="radio" name="payment_method" value="GCash" class="mt-1 accent-blue-600">
                                <span class="flex gap-3">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-xs font-extrabold text-white" aria-hidden="true">G</span>
                                    <span><strong class="block text-sm text-blue-950">GCash</strong><span class="mt-1 block text-xs text-stone-600">Transfer first, then submit your reference and payment screenshot for staff verification.</span></span>
                                </span>
                            </label>
                        </div>
                    </fieldset>
                    <div id="gcash-payment-fields" class="mb-4 hidden space-y-4 rounded-xl border border-blue-200 bg-blue-50 p-4">
                        <div class="rounded-lg bg-white p-3">
                            <p class="text-xs font-bold uppercase tracking-wide text-blue-800">Send payment to</p>
                            <p class="mt-1 text-sm font-extrabold text-stone-900">Rod Albarico</p>
                            <p class="text-sm font-semibold text-stone-700">09319430476</p>
                            <p class="mt-2 text-xs text-stone-600">Transfer the exact order total of <strong>₱<?= number_format($total, 2) ?></strong>. Your order will be prepared after staff verifies your payment.</p>
                        </div>
                        <div>
                            <label for="reference-number" class="mb-1 block text-sm font-semibold text-emerald-950">GCash reference number</label>
                            <input type="text" name="reference_number" id="reference-number" maxlength="100" placeholder="Enter the reference from your GCash receipt" class="w-full rounded-lg border border-blue-200 bg-white p-2.5 text-sm">
                        </div>
                        <div>
                            <label for="payment-proof" class="mb-1 block text-sm font-semibold text-emerald-950">Payment screenshot</label>
                            <input type="file" name="payment_proof" id="payment-proof" accept="image/jpeg,image/png,image/webp" class="w-full rounded-lg border border-blue-200 bg-white p-2 text-sm">
                            <p class="mt-1 text-xs text-stone-600">Required. JPG, PNG, or WEBP, up to 5 MB.</p>
                        </div>
                    </div>
                    <button type="submit" name="place_order" value="1"
                            <?= $hasUnavailableItems ? 'disabled' : '' ?>
                            class="w-full bg-emerald-800 hover:bg-emerald-700 text-white py-3 rounded-lg font-bold transition disabled:cursor-not-allowed disabled:opacity-50">
                        <?= $hasUnavailableItems ? 'Remove unavailable items first' : 'Confirm Order Placement' ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <script>
        (function () {
            const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
            const gcashPaymentFields = document.getElementById('gcash-payment-fields');
            const referenceNumber = document.getElementById('reference-number');
            const paymentProof = document.getElementById('payment-proof');
            const checkoutForm = document.getElementById('cart-checkout-form');

            function updatePaymentFields() {
                const selected = document.querySelector('input[name="payment_method"]:checked');
                if (!selected || !gcashPaymentFields) return;
                const isGcash = selected.value === 'GCash';

                // Only enable multipart when a file will actually be sent.
                // Prevents InfinityFree WAF 403 on empty file parts (COD).
                if (checkoutForm) {
                    if (isGcash) {
                        checkoutForm.setAttribute('enctype', 'multipart/form-data');
                    } else {
                        checkoutForm.removeAttribute('enctype');
                    }
                }

                gcashPaymentFields.classList.toggle('hidden', !isGcash);
                if (referenceNumber) referenceNumber.required = isGcash;
                if (paymentProof) paymentProof.required = isGcash;
            }
            paymentMethods.forEach(method => method.addEventListener('change', updatePaymentFields));
            updatePaymentFields();
        })();
    </script>
</body>
</html>