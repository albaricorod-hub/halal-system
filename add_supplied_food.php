<?php
require_once 'config.php';
checkAuth(['supplier', 'admin']);

$role       = strtolower(trim((string) $_SESSION['role']));
$supplierId = (int) ($_SESSION['supplier_id'] ?? 0);

if ($role === 'supplier' && $supplierId < 1) {
    die('Supplier account is not linked to a verified supplier profile.');
}

// Re-verify supplier verification status on every request (fix).
if ($role === 'supplier') {
    $verifyStmt = $pdo->prepare("SELECT id FROM halal_suppliers WHERE id = ? AND verification_status = 'verified' AND supplier_user_id = ? LIMIT 1");
    $verifyStmt->execute([$supplierId, (int) ($_SESSION['user_id'] ?? 0)]);
    if (!$verifyStmt->fetchColumn()) {
        renderForbidden('Your supplier profile is not verified. Please contact an administrator.');
    }
}

$supplierCsrf = csrfToken('add_food_csrf');

$categories = $pdo->query('SELECT id, name FROM food_categories ORDER BY name ASC')->fetchAll();
$supplierOptions = [];
if ($role === 'admin') {
    $supplierOptions = $pdo->query("SELECT id, supplier_name FROM halal_suppliers WHERE verification_status = 'verified' ORDER BY supplier_name ASC")->fetchAll();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('add_food_csrf');

    $name        = trim((string) ($_POST['name'] ?? ''));
    $categoryId  = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $price       = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    $ingredients = trim((string) ($_POST['ingredients'] ?? ''));
    $history     = trim((string) ($_POST['history'] ?? ''));
    $origin      = trim((string) ($_POST['origin'] ?? ''));
    $halalInfo   = trim((string) ($_POST['halal_info'] ?? ''));
    $assignedSupplier = $supplierId;

    if ($role === 'admin') {
        $posted = filter_input(INPUT_POST, 'supplier_id', FILTER_VALIDATE_INT);
        $assignedSupplier = $posted ?: 0;
        if ($assignedSupplier < 1) {
            $error = 'Select a verified supplier for this food item.';
        }
    }

    if ($error === '' && ($name === '' || !$categoryId || $price === false || $price < 0 || $ingredients === '')) {
        $error = 'Name, category, price, and ingredients are required.';
    }

    if ($error === '') {
        $insert = $pdo->prepare(
            "INSERT INTO foods (name, category_id, price, ingredients, history, origin, halal_info, supplier_id, is_heritage, is_available, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1, 'pending')"
        );
        $insert->execute([$name, $categoryId, $price, $ingredients, $history, $origin, $halalInfo, $assignedSupplier ?: null]);
        $success = 'Item submitted for review.';
        $_POST = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Supplied Item - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-emerald-950 via-emerald-900 to-stone-100 text-stone-800">
    <?php include 'navbar.php'; ?>
    <main class="max-w-3xl mx-auto px-4 py-10">
        <section class="rounded-3xl bg-white shadow-2xl border border-white/70 overflow-hidden">
            <div class="bg-emerald-950 px-6 py-8 sm:px-9 text-white">
                <a href="suppliers_dashboard.php" class="text-sm text-emerald-200 hover:text-white">&larr; Supplier dashboard</a>
                <p class="text-xs uppercase tracking-[0.2em] text-amber-300 font-bold mt-7">Supply hub</p>
                <h1 class="text-3xl font-extrabold mt-2">Register a new item</h1>
                <p class="text-emerald-100 text-sm mt-2">Submit a dish or ingredient for administrator verification.</p>
            </div>
            <form method="POST" class="p-6 sm:p-9 space-y-5">
                <input type="hidden" name="csrf_token" value="<?= h($supplierCsrf) ?>">
                <?php if ($error !== ''): ?><div class="rounded-xl bg-rose-50 border border-rose-200 p-3 text-sm text-rose-800 font-semibold"><?= h($error) ?></div><?php endif; ?>
                <?php if ($success !== ''): ?><div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-sm text-emerald-800 font-semibold"><?= h($success) ?></div><?php endif; ?>
                <div class="grid sm:grid-cols-2 gap-5">
                    <?php if ($role === 'admin'): ?>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-bold mb-1" for="supplier_id">Supplier (required)</label>
                            <select id="supplier_id" name="supplier_id" required class="w-full rounded-xl border border-stone-300 px-4 py-3 bg-white">
                                <option value="">Choose verified supplier</option>
                                <?php foreach ($supplierOptions as $opt): ?>
                                    <option value="<?= (int) $opt['id'] ?>" <?= (($_POST['supplier_id'] ?? '') == $opt['id']) ? 'selected' : '' ?>><?= h($opt['supplier_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="sm:col-span-2"><label class="block text-sm font-bold mb-1" for="name">Item name</label><input id="name" name="name" required value="<?= h($_POST['name'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-4 py-3"></div>
                    <div><label class="block text-sm font-bold mb-1" for="category_id">Category</label><select id="category_id" name="category_id" required class="w-full rounded-xl border border-stone-300 px-4 py-3 bg-white"><option value="">Choose category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (($_POST['category_id'] ?? '') == $c['id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
                    <div><label class="block text-sm font-bold mb-1" for="price">Price</label><input id="price" name="price" type="number" step="0.01" min="0" required value="<?= h($_POST['price'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-4 py-3"></div>
                    <div class="sm:col-span-2"><label class="block text-sm font-bold mb-1" for="ingredients">Ingredients</label><textarea id="ingredients" name="ingredients" required rows="3" class="w-full rounded-xl border border-stone-300 px-4 py-3"><?= h($_POST['ingredients'] ?? '') ?></textarea></div>
                    <div><label class="block text-sm font-bold mb-1" for="origin">Farm / source origin</label><input id="origin" name="origin" value="<?= h($_POST['origin'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-4 py-3"></div>
                    <div><label class="block text-sm font-bold mb-1" for="halal_info">Halal information &amp; slaughter method</label><input id="halal_info" name="halal_info" value="<?= h($_POST['halal_info'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-4 py-3"></div>
                    <div class="sm:col-span-2"><label class="block text-sm font-bold mb-1" for="history">Heritage or product story</label><textarea id="history" name="history" rows="4" class="w-full rounded-xl border border-stone-300 px-4 py-3"><?= h($_POST['history'] ?? '') ?></textarea></div>
                </div>
                <button type="submit" class="w-full rounded-xl bg-emerald-800 hover:bg-emerald-700 text-white py-3 font-extrabold transition">Submit item for review</button>
            </form>
        </section>
    </main>
</body>
</html>