<?php
require_once 'config.php';
checkAuth(['supplier', 'admin']);
ensureHalalSupplierDirectoryColumns($pdo);

$role       = strtolower(trim((string) $_SESSION['role']));
$supplierId = (int) ($_SESSION['supplier_id'] ?? 0);

if ($role === 'admin') {
    header('Location: halal_suppliers.php');
    exit;
}

if ($supplierId < 1) {
    die('Supplier profile is not linked. Contact an administrator.');
}

$supplierInfo = $pdo->prepare('SELECT * FROM halal_suppliers WHERE id = ? LIMIT 1');
$supplierInfo->execute([$supplierId]);
$supplier = $supplierInfo->fetch();

if (!$supplier || strtolower((string) ($supplier['verification_status'] ?? '')) !== 'verified') {
    die('Your supplier profile is not currently verified.');
}

$certificationNumber = trim((string) ($supplier['certification_no'] ?? ''));

$foods = $pdo->prepare('SELECT f.*, c.name AS category_name FROM foods f LEFT JOIN food_categories c ON f.category_id = c.id WHERE f.supplier_id = ? ORDER BY f.id DESC');
$foods->execute([$supplierId]);
$suppliedItems = $foods->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Halal Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen">
    <?php include 'navbar.php'; ?>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="bg-emerald-900 text-white p-6 rounded-xl shadow mb-8 flex flex-wrap justify-between items-center gap-3">
            <div>
                <h1 class="text-2xl font-bold"><?= h($supplier['supplier_name']) ?> Dashboard</h1>
                <p class="text-emerald-200 text-sm">Certificate ID: <?php if ($certificationNumber !== ''): ?><code class="bg-emerald-800 px-2 py-0.5 rounded"><?= h($certificationNumber) ?></code><?php else: ?><span class="font-semibold">No certificate ID on file</span><?php endif; ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="halal_suppliers.php" class="rounded-lg bg-amber-500 px-3 py-2 text-xs font-extrabold text-emerald-950 hover:bg-amber-400">Browse verified suppliers</a>
                <a href="logout.php" class="bg-red-700 hover:bg-red-800 text-white text-xs px-3 py-2 rounded font-bold">Logout</a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-4 rounded-xl shadow border border-stone-200">
                <span class="text-xs text-stone-500 font-bold uppercase">Supplied Foods</span>
                <div class="text-3xl font-bold text-emerald-950"><?= count($suppliedItems) ?></div>
            </div>
            <div class="bg-white p-4 rounded-xl shadow border border-stone-200">
                <span class="text-xs text-stone-500 font-bold uppercase">Certification Status</span>
                <div class="text-lg font-bold text-stone-600 mt-1"><?= $certificationNumber !== '' ? 'Certificate ID on file' : 'Not provided' ?></div>
            </div>
            <div class="bg-white p-4 rounded-xl shadow border border-stone-200">
                <span class="text-xs text-stone-500 font-bold uppercase">Verification</span>
                <div class="text-lg font-bold text-emerald-700 mt-1">Verified supplier</div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden border border-stone-200">
            <div class="p-4 bg-emerald-950 text-white font-bold flex justify-between items-center">
                <span>Halal Ingredients &amp; Dishes Supplied</span>
                <a href="add_supplied_food.php" class="bg-emerald-700 text-xs px-3 py-1.5 rounded hover:bg-emerald-600">+ Register New Item</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-stone-100 border-b">
                        <tr><th class="p-3">ID</th><th class="p-3">Item</th><th class="p-3">Category</th><th class="p-3">Price</th><th class="p-3">Status</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php if (empty($suppliedItems)): ?>
                            <tr><td colspan="5" class="p-4 text-center text-stone-500">No items registered yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($suppliedItems as $item): ?>
                                <tr>
                                    <td class="p-3">#<?= (int) $item['id'] ?></td>
                                    <td class="p-3 font-bold text-emerald-950"><?= h($item['name']) ?></td>
                                    <td class="p-3"><?= h($item['category_name'] ?? 'General') ?></td>
                                    <td class="p-3 font-semibold">₱<?= number_format((float) $item['price'], 2) ?></td>
                                    <td class="p-3"><span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded text-xs font-bold"><?= h($item['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>