<?php
require_once 'config.php';
checkAuth(['staff', 'admin']);

$foodCsrf = csrfToken('edit_food_csrf');
$foodId   = (int) ($_GET['id'] ?? 0);

if (!$foodId) {
    header('Location: menu.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM foods WHERE id = ?');
$stmt->execute([$foodId]);
$food = $stmt->fetch();

if (!$food) {
    header('Location: menu.php');
    exit;
}

$categories = $pdo->query('SELECT * FROM food_categories ORDER BY name ASC')->fetchAll();
$suppliers  = $pdo->query('SELECT id, supplier_name FROM halal_suppliers WHERE verification_status = \'verified\' ORDER BY supplier_name ASC')->fetchAll();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('edit_food_csrf');

    $name        = trim((string) ($_POST['name'] ?? ''));
    $categoryId  = (int) ($_POST['category_id'] ?? 0);
    $price       = (float) ($_POST['price'] ?? 0);
    $ingredients = trim((string) ($_POST['ingredients'] ?? ''));
    $supplierId  = (int) ($_POST['supplier_id'] ?? 0);
    $isHeritage  = isset($_POST['is_heritage']) ? 1 : 0;
    $status      = (string) ($_POST['status'] ?? 'approved');

    if ($name === '')        $errors[] = 'Dish name is required.';
    if ($categoryId <= 0)    $errors[] = 'Please select a category.';
    if ($price < 0)          $errors[] = 'Price must be a positive number.';
    if ($ingredients === '') $errors[] = 'Description/ingredients are required.';
    if (!in_array($status, ['approved', 'pending', 'rejected', 'archived'], true)) $errors[] = 'Invalid status.';

    if (empty($errors)) {
        $pdo->prepare(
            "UPDATE foods SET name = ?, category_id = ?, price = ?, ingredients = ?, supplier_id = ?, is_heritage = ?, status = ? WHERE id = ?"
        )->execute([$name, $categoryId, $price, $ingredients, $supplierId ?: null, $isHeritage, $status, $foodId]);

        $success = true;

        $stmt = $pdo->prepare('SELECT * FROM foods WHERE id = ?');
        $stmt->execute([$foodId]);
        $food = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Dish - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/system-theme.css">
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen flex flex-col font-sans">
    <nav class="sticky top-0 z-40 border-b border-emerald-900 bg-emerald-950 text-white shadow-md">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3">
            <div class="flex min-w-0 items-center gap-3">
                <?php $brandTone = 'dark'; $brandHref = 'staff_dashboard.php'; include 'brand_logo.php'; ?>
                <span class="hidden text-sm font-semibold text-emerald-100 sm:inline">Edit Food Dish</span>
            </div>
            <a href="menu.php" class="rounded-lg border border-emerald-700 px-4 py-2 text-xs font-bold text-white transition hover:bg-emerald-900">&#8592; Back to Menu</a>
        </div>
    </nav>
    <main class="max-w-4xl mx-auto px-4 py-8 flex-grow w-full">
        <div class="mb-8">
            <h1 class="text-3xl font-extrabold text-emerald-950 mb-2">Edit Dish: <?= h($food['name']) ?></h1>
        </div>

        <?php if ($success): ?>
            <div class="mb-6 p-4 bg-emerald-900 text-emerald-50 border border-emerald-800 rounded-xl flex justify-between items-center shadow-md">
                <span class="text-sm font-semibold">Dish updated successfully!</span>
                <a href="menu.php" class="bg-amber-500 hover:bg-amber-400 text-emerald-950 px-3.5 py-1.5 rounded-lg text-xs font-extrabold transition">Back to Menu</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-6 p-4 bg-red-900 text-red-50 border border-red-800 rounded-xl shadow-md">
                <ul class="list-disc list-inside space-y-1 text-sm">
                    <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" class="bg-white rounded-2xl shadow-sm border border-stone-200 p-8 space-y-6">
            <input type="hidden" name="csrf_token" value="<?= h($foodCsrf) ?>">

            <div>
                <label for="name" class="block text-sm font-bold text-emerald-950 mb-2">Dish Name <span class="text-red-600">*</span></label>
                <input type="text" id="name" name="name" value="<?= h($food['name']) ?>" required class="w-full px-4 py-3 border border-stone-300 rounded-xl">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="category_id" class="block text-sm font-bold text-emerald-950 mb-2">Category <span class="text-red-600">*</span></label>
                    <select id="category_id" name="category_id" required class="w-full px-4 py-3 border border-stone-300 rounded-xl bg-white">
                        <option value="">-- Select category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= ((int) $food['category_id'] === (int) $cat['id']) ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="is_heritage" class="block text-sm font-bold text-emerald-950 mb-2">Heritage Dish</label>
                    <div class="flex items-center gap-3 pt-2">
                        <input type="checkbox" id="is_heritage" name="is_heritage" <?= $food['is_heritage'] ? 'checked' : '' ?> class="w-5 h-5 text-emerald-800 border-stone-300 rounded">
                        <label for="is_heritage" class="text-sm text-stone-600">Mark as heritage/signature dish</label>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="price" class="block text-sm font-bold text-emerald-950 mb-2">Price (PHP) <span class="text-red-600">*</span></label>
                    <input type="number" id="price" name="price" step="0.01" min="0" value="<?= h(number_format((float) $food['price'], 2, '.', '')) ?>" required class="w-full px-4 py-3 border border-stone-300 rounded-xl">
                </div>
                <div>
                    <label for="supplier_id" class="block text-sm font-bold text-emerald-950 mb-2">Halal Supplier</label>
                    <select id="supplier_id" name="supplier_id" class="w-full px-4 py-3 border border-stone-300 rounded-xl bg-white">
                        <option value="">-- No supplier assigned --</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= (int) $sup['id'] ?>" <?= ((int) $food['supplier_id'] === (int) $sup['id']) ? 'selected' : '' ?>><?= h($sup['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label for="ingredients" class="block text-sm font-bold text-emerald-950 mb-2">Dish Description / Ingredients <span class="text-red-600">*</span></label>
                <textarea id="ingredients" name="ingredients" rows="4" required class="w-full px-4 py-3 border border-stone-300 rounded-xl resize-none"><?= h($food['ingredients'] ?? '') ?></textarea>
            </div>

            <div>
                <label for="status" class="block text-sm font-bold text-emerald-950 mb-2">Availability Status <span class="text-red-600">*</span></label>
                <select id="status" name="status" required class="w-full px-4 py-3 border border-stone-300 rounded-xl bg-white">
                    <option value="approved"  <?= $food['status'] === 'approved'  ? 'selected' : '' ?>>&#10003; Approved (Available for Order)</option>
                    <option value="pending"   <?= $food['status'] === 'pending'   ? 'selected' : '' ?>>&#9203; Pending (Under Review)</option>
                    <option value="rejected"  <?= $food['status'] === 'rejected'  ? 'selected' : '' ?>>&#10060; Rejected (Not Approved)</option>
                    <option value="archived"  <?= $food['status'] === 'archived'  ? 'selected' : '' ?>>&#128230; Archived (Hidden from Menu)</option>
                </select>
            </div>

            <div class="flex gap-3 pt-6 border-t border-stone-200">
                <button type="submit" class="flex-1 bg-emerald-800 hover:bg-emerald-700 text-white px-6 py-3 rounded-lg font-bold transition">Save Changes</button>
                <a href="menu.php" class="flex-1 bg-stone-200 hover:bg-stone-300 text-stone-900 px-6 py-3 rounded-lg font-bold transition text-center">Cancel</a>
            </div>
        </form>
    </main>
</body>
</html>