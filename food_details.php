<?php
require_once 'config.php';

$foodId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$foodId) {
    header('Location: index.php');
    exit;
}

$certificationSelect = halalSupplierCertificationSelect($pdo);
$stmt = $pdo->prepare("SELECT f.*, c.name AS category_name, s.supplier_name, $certificationSelect
    FROM foods f
    LEFT JOIN food_categories c ON f.category_id = c.id
    LEFT JOIN halal_suppliers s ON f.supplier_id = s.id
    WHERE f.id = ? AND f.status = 'approved'
    LIMIT 1");
$stmt->execute([$foodId]);
$food = $stmt->fetch();

if (!$food) {
    http_response_code(404);
    $pageError = 'This dish is no longer available.';
}

$foodImages = file_exists('food_images.php') ? require 'food_images.php' : [];
$heroImage  = isset($food) ? ($foodImages[$food['name']] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($food) ? h($food['name']) : 'Dish Not Found' ?> - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-stone-50 text-stone-800">
    <?php include 'navbar.php'; ?>
    <main class="max-w-4xl mx-auto px-4 py-10">
        <?php if (!isset($food)): ?>
            <section class="rounded-3xl bg-white border border-stone-200 shadow-sm p-10 text-center">
                <h1 class="text-2xl font-bold text-emerald-950 mb-3">Dish not found</h1>
                <p class="text-stone-600 mb-6"><?= h($pageError ?? 'This dish could not be loaded.') ?></p>
                <a href="menu.php" class="inline-flex bg-emerald-800 hover:bg-emerald-700 text-white px-5 py-3 rounded-xl font-bold text-sm">Return to menu</a>
            </section>
        <?php else: ?>
            <a href="index.php" class="text-sm font-bold text-emerald-800 hover:text-emerald-600">&larr; Back to heritage archive</a>
            <section class="mt-5 overflow-hidden rounded-3xl bg-white border border-stone-200 shadow-sm">
                <div class="relative bg-gradient-to-br from-emerald-950 to-emerald-800 px-6 py-12 sm:px-10 text-white <?= $heroImage ? 'min-h-[14rem]' : '' ?>">
                    <?php if ($heroImage): ?>
                        <img src="<?= h($heroImage) ?>" alt="" class="absolute inset-0 h-full w-full object-cover opacity-35" aria-hidden="true">
                    <?php endif; ?>
                    <div class="relative">
                        <p class="text-xs uppercase tracking-[0.2em] text-amber-300 font-bold mb-4"><?= h($food['category_name'] ?? 'Halal dish') ?></p>
                        <h1 class="text-3xl sm:text-5xl font-extrabold max-w-2xl"><?= h($food['name']) ?></h1>
                        <p class="mt-4 text-emerald-100 max-w-2xl leading-relaxed"><?= h($food['history'] ?? $food['ingredients'] ?? 'Authentic halal preparation.') ?></p>
                    </div>
                </div>
                <div class="p-6 sm:p-10 grid gap-8 md:grid-cols-[1fr_auto]">
                    <div class="space-y-5">
                        <div>
                            <h2 class="text-xs uppercase tracking-wider font-bold text-emerald-800 mb-2">Ingredients</h2>
                            <p class="text-stone-700 leading-relaxed"><?= h($food['ingredients'] ?? 'Prepared with verified halal ingredients.') ?></p>
                        </div>
                        <?php renderHalalTraceabilityTag($food); ?>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div class="rounded-2xl bg-stone-50 border border-stone-200 p-4">
                                <p class="text-xs text-stone-500 uppercase font-bold mb-1">Origin</p>
                                <p class="font-bold text-emerald-950"><?= h($food['origin'] ?? 'Heritage kitchen') ?></p>
                            </div>
                            <div class="rounded-2xl bg-stone-50 border border-stone-200 p-4">
                                <p class="text-xs text-stone-500 uppercase font-bold mb-1">Supplier</p>
                                <p class="font-bold text-emerald-950"><?= h($food['supplier_name'] ?? 'Supplier not listed') ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="md:min-w-52 md:text-right">
                        <p class="text-xs uppercase tracking-wider font-bold text-stone-500">Price</p>
                        <p class="text-3xl font-extrabold text-emerald-950 mt-1">₱<?= number_format((float) $food['price'], 2) ?></p>
                        <?php if (strtolower((string) ($_SESSION['role'] ?? '')) === 'customer'): ?>
                            <form method="POST" action="menu.php" class="mt-5">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken('cart_csrf')) ?>">
                                <button type="submit" name="quick_add" value="<?= (int) $food['id'] ?>" class="w-full md:w-auto inline-flex justify-center bg-amber-500 hover:bg-amber-400 text-emerald-950 px-5 py-3 rounded-xl font-extrabold text-sm">Add to cart</button>
                            </form>
                        <?php else: ?>
                            <a href="menu.php" class="mt-5 inline-flex w-full md:w-auto justify-center bg-emerald-800 hover:bg-emerald-700 text-white px-5 py-3 rounded-xl font-extrabold text-sm">View menu</a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>