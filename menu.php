<?php
require_once 'config.php';

$userRole = strtolower(trim((string) ($_SESSION['role'] ?? 'guest')));
$isCustomer = $userRole === 'customer';
$isStaffMenu = in_array($userRole, ['staff', 'admin'], true);
$canAddToCart = $isCustomer;

$cartCsrf = csrfToken('cart_csrf');

/* ------------------------------------------------------------------ */
/* POST: add customized item                                           */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customized_item'])) {
    requireCsrf('cart_csrf');
    checkAuth(['customer']);

    $foodId           = filter_input(INPUT_POST, 'food_id', FILTER_VALIDATE_INT);
    $selectedCut      = is_string($_POST['custom_cut'] ?? null) ? $_POST['custom_cut'] : 'none';
    $selectedToppings = $_POST['custom_toppings'] ?? [];
    $cutOptions       = halalMeatCutOptions();

    if (!$foodId || !is_string($selectedCut) || !array_key_exists($selectedCut, $cutOptions) || !is_array($selectedToppings)) {
        http_response_code(400);
        exit('Choose valid dish customizations.');
    }

    $foodStmt = $pdo->prepare("SELECT id, name, ingredients FROM foods WHERE id = ? AND status = 'approved' LIMIT 1");
    $foodStmt->execute([$foodId]);
    $selectedFood = $foodStmt->fetch();

    if (!$selectedFood) {
        http_response_code(400);
        exit('This dish is not available.');
    }

    $toppingOptions = halalDishCustomizationOptions($selectedFood);
    $validToppings  = [];
    foreach ($selectedToppings as $topping) {
        if (!is_string($topping) || !array_key_exists($topping, $toppingOptions)) {
            http_response_code(400);
            exit('Choose valid custom options for this dish.');
        }
        $validToppings[$topping] = true;
    }
    if ($selectedCut !== 'none' && !foodSupportsCustomMeatCuts($selectedFood)) {
        http_response_code(400);
        exit('Customizations are not available for this dish.');
    }

    $_SESSION['cart'][$foodId]          = (int) ($_SESSION['cart'][$foodId] ?? 0) + 1;
    $_SESSION['cart_cuts'][$foodId]     = $selectedCut;
    $_SESSION['cart_toppings'][$foodId] = array_keys($validToppings);

    header('Location: cart.php');
    exit;
}

/* ------------------------------------------------------------------ */
/* POST: quick add                                                     */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_add'])) {
    requireCsrf('cart_csrf');
    checkAuth(['customer']);

    $foodId = filter_var($_POST['quick_add'], FILTER_VALIDATE_INT);
    if (!$foodId) {
        header('Location: menu.php');
        exit;
    }

    $foodStmt = $pdo->prepare("SELECT id FROM foods WHERE id = ? AND status = 'approved' LIMIT 1");
    $foodStmt->execute([$foodId]);
    if (!$foodStmt->fetchColumn()) {
        header('Location: menu.php?error=unavailable');
        exit;
    }

    $_SESSION['cart'][$foodId]      = (int) ($_SESSION['cart'][$foodId] ?? 0) + 1;
    $_SESSION['cart_cuts'][$foodId] = $_SESSION['cart_cuts'][$foodId] ?? 'none';

    header('Location: menu.php?added=1');
    exit;
}

/* ------------------------------------------------------------------ */
/* One-time seed of default dishes                                     */
/* ------------------------------------------------------------------ */
function seedDefaultFoods(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $pdo->exec("INSERT IGNORE INTO food_categories (id, name) VALUES
        (1, 'Traditional Heritage'),
        (2, 'Halal Barbecue & Grills'),
        (3, 'Rice & Noodles'),
        (4, 'Desserts & Beverages')");

    $defaultFoods = [
        ['Tiula Itum Tausug Soup', 1, 260.00, 'Black coconut braised beef soup with lemongrass and native spices.', 1],
        ['Rendang Sapi (Spiced Beef Rendang)', 1, 280.00, 'Slow-cooked halal beef in coconut milk, lemongrass, and kaffir lime.', 1],
        ['Chicken Biryani Special', 3, 220.00, 'Aromatic basmati rice cooked with saffron and whole spices.', 1],
        ['Beef Satay with Peanut Sauce', 2, 180.00, 'Grilled marinated beef skewers served with authentic peanut dip.', 0],
        ['Satti Pasay Skewers', 2, 165.00, 'Grilled skewers served with taumu rice and spicy peanut broth.', 1],
        ['Mami & Siopao Halal Combo', 3, 150.00, 'Savory beef noodle soup paired with steamed halal chicken buns.', 0],
        ['Beef Pyanggang Maranao', 1, 260.00, 'Heritage Moro dish made with toasted coconut paste and native spices.', 1],
        ['Chicken Piaparan Maranao', 1, 240.00, 'Simmered chicken in coconut milk, turmeric, and spicy scallion palapa.', 1],
        ['Halal Grilled Chicken Inasal', 2, 195.00, 'Chicken leg marinated in calamansi, annatto oil, and lemongrass.', 0],
        ['Kuning Rice Meal (Moro Yellow Rice)', 3, 175.00, 'Turmeric infused rice served with fried tuna flakes and boiled egg.', 1],
        ['Halo-Halo Special (Halal Certified)', 4, 95.00, 'Shaved ice with sweet beans, coconut gel, and evaporated milk.', 0],
        ['Iced Arabica Teh Tarik', 4, 65.00, 'Frothy pulled black tea mixed with sweet condensed milk.', 0],
        ['Lamb Kabsa Heritage Platter', 1, 320.00, 'Fragrant spiced rice with tender halal lamb and toasted almonds.', 1],
        ['Crispy Chicken Pastil', 3, 135.00, 'Seasoned shredded chicken over turmeric rice with cucumber relish.', 1],
        ['Tandoori Chicken Quarter', 2, 210.00, 'Char-grilled chicken marinated in yogurt, garlic, ginger, and spices.', 0],
        ['Seafood Laksa Bowl', 3, 195.00, 'Rice noodles in a rich coconut broth with prawns and herbs.', 0],
        ['Beef Kulma Curry', 1, 245.00, 'Slow-braised beef in coconut curry with potatoes and aromatics.', 1],
        ['Pandan Coconut Pudding', 4, 85.00, 'Silky pandan custard finished with coconut cream.', 0],
        ['Mango Sago Cooler', 4, 90.00, 'Chilled mango, coconut sago, and sweet cream.', 0],
    ];

    $check  = $pdo->prepare('SELECT id FROM foods WHERE name = ? LIMIT 1');
    $insert = $pdo->prepare("INSERT INTO foods (name, category_id, price, ingredients, is_heritage, status) VALUES (?, ?, ?, ?, ?, 'approved')");
    foreach ($defaultFoods as $row) {
        $check->execute([$row[0]]);
        if (!$check->fetch()) {
            $insert->execute($row);
        }
    }
}
seedDefaultFoods($pdo);

/* ------------------------------------------------------------------ */
/* Query                                                               */
/* ------------------------------------------------------------------ */
$catFilter           = isset($_GET['category']) ? (int) $_GET['category'] : 0;
$certificationSelect = halalSupplierCertificationSelect($pdo);

$query = "SELECT f.*, c.name AS category_name, s.supplier_name, $certificationSelect
          FROM foods f
          LEFT JOIN food_categories c ON f.category_id = c.id
          LEFT JOIN halal_suppliers s ON f.supplier_id = s.id
          WHERE f.status = 'approved'";
$params = [];
if ($catFilter > 0) {
    $query .= ' AND f.category_id = ?';
    $params[] = $catFilter;
}
$query .= ' ORDER BY f.id DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$menuItems = $stmt->fetchAll();

$categories = $pdo->query('SELECT * FROM food_categories ORDER BY name ASC')->fetchAll();
$foodImages = file_exists('food_images.php') ? require 'food_images.php' : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isStaffMenu ? 'Manage Food Menu' : 'Halal Ordering Menu' ?> - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>.menu-food-card[hidden] { display: none !important; }</style>
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen flex flex-col font-sans">
    <?php if ($isStaffMenu): ?>
        <nav class="sticky top-0 z-40 border-b border-emerald-900 bg-emerald-950 text-white shadow-md">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div class="flex min-w-0 items-center gap-3">
                    <?php $brandTone = 'dark'; $brandHref = $userRole === 'admin' ? 'admin_dashboard.php' : 'staff_dashboard.php'; include 'brand_logo.php'; ?>
                    <span class="hidden text-sm font-semibold text-emerald-100 sm:inline">Food Management</span>
                </div>
                <a href="<?= $userRole === 'admin' ? 'admin_dashboard.php' : 'staff_dashboard.php' ?>" class="rounded-lg border border-emerald-700 px-4 py-2 text-xs font-bold text-white transition hover:bg-emerald-900">Back to dashboard</a>
            </div>
        </nav>
    <?php else: ?>
        <?php include 'navbar.php'; ?>
    <?php endif; ?>

    <main class="max-w-7xl mx-auto px-4 py-8 flex-grow w-full">
        <header class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-end border-b border-stone-300 pb-5 gap-4">
            <div>
                <h1 class="text-3xl font-extrabold text-emerald-950 flex items-center gap-2.5">
                    <svg class="w-8 h-8 text-emerald-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                    <span><?= $isStaffMenu ? 'Manage Food Menu' : 'Halal Culinary Menu' ?></span>
                </h1>
                <p class="text-stone-600 text-sm mt-1"><?= $isStaffMenu ? 'Review dishes and update prices or food details.' : 'Freshly prepared, certified Halal dishes available for direct ordering.' ?></p>
            </div>

            <?php if ($isCustomer): ?>
                <div class="flex flex-wrap gap-2.5">
                    <a href="cart.php" class="bg-emerald-800 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-xs font-bold transition shadow-sm border border-emerald-900 flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"></path></svg>
                        <span>View Cart &amp; Checkout</span>
                    </a>
                    <a href="order.php" class="bg-amber-500 hover:bg-amber-400 text-emerald-950 px-4 py-2 rounded-lg text-xs font-bold transition shadow-sm border border-amber-600 flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-950" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                        <span>Track My Orders</span>
                    </a>
                </div>
            <?php elseif ($userRole === 'supplier'): ?>
                <a href="suppliers_dashboard.php" class="bg-amber-500 hover:bg-amber-400 text-emerald-950 px-4 py-2 rounded-lg text-xs font-bold transition shadow-sm border border-amber-600">Supplier dashboard</a>
            <?php elseif ($userRole === 'guest'): ?>
                <a href="login.php" class="bg-emerald-800 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-xs font-bold transition shadow-sm border border-emerald-900">Sign in to order</a>
            <?php endif; ?>
        </header>

        <?php if (isset($_GET['added'])): ?>
            <p role="status" class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">Item added to your cart.</p>
        <?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'unavailable'): ?>
            <p role="alert" class="mb-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">That dish is not available right now.</p>
        <?php endif; ?>

        <div id="menu-search-container" class="relative z-20 mb-6 max-w-xl">
            <label for="menu-search" class="mb-2 block text-sm font-bold text-emerald-950">Find a dish</label>
            <input id="menu-search" type="search" placeholder="Start typing a dish name..."
                   class="w-full rounded-xl border border-stone-300 bg-white py-3 pl-4 pr-4 text-sm shadow-sm outline-none transition focus:border-emerald-700 focus:ring-2 focus:ring-emerald-100">
        </div>

        <div class="flex flex-wrap gap-2 mb-8">
            <a href="menu.php" class="px-4 py-2 rounded-full text-xs font-bold transition border shadow-sm <?= ($catFilter === 0) ? 'bg-emerald-900 text-amber-400 border-emerald-900' : 'bg-white text-stone-700 border-stone-300 hover:bg-emerald-50' ?>">All Dishes</a>
            <?php foreach ($categories as $cat): ?>
                <a href="menu.php?category=<?= (int) $cat['id'] ?>" class="px-4 py-2 rounded-full text-xs font-bold transition border shadow-sm <?= ($catFilter === (int) $cat['id']) ? 'bg-emerald-900 text-amber-400 border-emerald-900' : 'bg-white text-stone-700 border-stone-300 hover:bg-emerald-50' ?>"><?= h($cat['name']) ?></a>
            <?php endforeach; ?>
        </div>

        <div id="menu-food-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php foreach ($menuItems as $item): ?>
                <?php $foodImage = $foodImages[$item['name']] ?? 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=800&q=80'; ?>
                <div class="menu-food-card h-full bg-white rounded-2xl shadow-sm hover:shadow-md transition border border-stone-200 overflow-hidden flex flex-col" data-food-name="<?= h($item['name']) ?>">
                    <img src="<?= h($foodImage) ?>" alt="<?= h($item['name']) ?>" class="w-full h-44 shrink-0 object-cover" loading="lazy">
                    <div class="p-5 flex flex-1 flex-col">
                        <div class="mb-3 flex h-6 flex-wrap items-center gap-1.5">
                            <span class="text-[10px] uppercase tracking-wider bg-emerald-100 text-emerald-900 px-2 py-0.5 rounded-md font-extrabold"><?= h($item['category_name'] ?? 'Halal Dish') ?></span>
                        </div>
                        <h3 class="mb-1.5 min-h-14 text-lg font-bold leading-7 text-emerald-950 line-clamp-2"><?= h($item['name']) ?></h3>
                        <p class="mb-4 min-h-10 text-xs leading-relaxed text-stone-600 line-clamp-2"><?= h($item['ingredients'] ?? 'Authentic Halal ingredients.') ?></p>
                        <?php renderHalalTraceabilityTag($item); ?>
                    </div>

                    <div class="mt-auto border-t border-stone-200 bg-stone-50 p-4">
                        <div class="mb-3 min-h-8 text-center text-xl font-extrabold leading-8 text-emerald-950">₱<?= number_format((float) $item['price'], 2) ?></div>
                        <?php if ($isStaffMenu): ?>
                            <a href="edit_food.php?id=<?= (int) $item['id'] ?>" class="flex min-h-10 w-full items-center justify-center rounded-lg bg-emerald-800 px-3.5 py-2 text-xs font-bold text-white hover:bg-emerald-700">Edit dish</a>
                        <?php elseif ($isCustomer): ?>
                            <div class="grid grid-cols-2 gap-2">
                                <form method="POST" action="menu.php" class="contents">
                                    <input type="hidden" name="csrf_token" value="<?= h($cartCsrf) ?>">
                                    <button type="submit" name="quick_add" value="<?= (int) $item['id'] ?>" class="flex min-h-10 items-center justify-center rounded-lg bg-emerald-800 px-2 py-2 text-center text-xs font-bold text-white hover:bg-emerald-700">Add to Cart</button>
                                </form>
                                <details class="group" data-customize-details>
                                    <summary class="flex min-h-10 cursor-pointer list-none items-center justify-center rounded-lg border border-emerald-800 bg-white px-2 py-2 text-center text-xs font-bold text-emerald-900 hover:bg-emerald-50 [&::-webkit-details-marker]:hidden">Customize Item</summary>
                                    <form method="POST" action="menu.php" class="mt-3 w-full space-y-2 rounded-lg border border-stone-200 bg-white p-3">
                                        <input type="hidden" name="csrf_token" value="<?= h($cartCsrf) ?>">
                                        <input type="hidden" name="food_id" value="<?= (int) $item['id'] ?>">
                                        <?php if (foodSupportsCustomMeatCuts($item)): ?>
                                            <label for="custom-cut-<?= (int) $item['id'] ?>" class="block text-xs font-bold text-emerald-950">Choose meat cut</label>
                                            <select id="custom-cut-<?= (int) $item['id'] ?>" name="custom_cut" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs text-stone-800">
                                                <?php foreach (halalMeatCutOptions() as $cutValue => $cutLabel): ?>
                                                    <option value="<?= h($cutValue) ?>"><?= h($cutLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                        <?php $customizationOptions = halalDishCustomizationOptions($item); ?>
                                        <fieldset class="space-y-1.5">
                                            <legend class="text-xs font-bold text-emerald-950"><?= foodSupportsCustomMeatCuts($item) ? 'Additional options' : 'Choose options' ?></legend>
                                            <?php foreach ($customizationOptions as $optionValue => $optionLabel): ?>
                                                <label class="flex items-center gap-2 text-xs text-stone-700">
                                                    <input type="checkbox" name="custom_toppings[]" value="<?= h($optionValue) ?>" class="rounded border-stone-300 text-emerald-800 focus:ring-emerald-700">
                                                    <span><?= h($optionLabel) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <button type="submit" name="add_customized_item" value="1" class="w-full rounded-lg bg-emerald-800 px-3.5 py-2 text-xs font-bold text-white hover:bg-emerald-700">Add Customized Item</button>
                                    </form>
                                </details>
                            </div>
                        <?php elseif ($userRole === 'guest'): ?>
                            <a href="login.php" class="flex min-h-10 w-full items-center justify-center rounded-lg bg-emerald-800 px-3.5 py-2 text-xs font-bold text-white hover:bg-emerald-700">Sign in to order</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <script>
        const menuSearch = document.getElementById('menu-search');
        const menuFoodCards = Array.from(document.querySelectorAll('.menu-food-card'));
        if (menuSearch) {
            menuSearch.addEventListener('input', () => {
                const query = menuSearch.value.trim().toLowerCase();
                menuFoodCards.forEach(card => {
                    card.hidden = query !== '' && !card.dataset.foodName.toLowerCase().includes(query);
                });
            });
        }
        document.querySelectorAll('[data-customize-details]').forEach(details => {
            details.addEventListener('toggle', () => {
                if (!details.open) return;
                const form = details.querySelector('form');
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                form.querySelector('select, input[type="checkbox"]')?.focus({ preventScroll: true });
            });
        });
    </script>
</body>
</html>