<?php
require_once 'config.php';
ensureDefaultFoods($pdo);

$search      = trim((string) ($_GET['search'] ?? ''));
$catFilter   = trim((string) ($_GET['category'] ?? ''));
$certificationSelect = halalSupplierCertificationSelect($pdo);

$sql = "SELECT f.*, c.name AS category_name, s.supplier_name, $certificationSelect
        FROM foods f
        JOIN food_categories c ON f.category_id = c.id
        LEFT JOIN halal_suppliers s ON f.supplier_id = s.id
        WHERE f.status = 'approved'";
$params = [];
if ($search !== '') {
    $sql .= ' AND (f.name LIKE ? OR f.history LIKE ? OR f.ingredients LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}
if ($catFilter !== '' && ctype_digit($catFilter)) {
    $sql .= ' AND f.category_id = ?';
    $params[] = (int) $catFilter;
}
$sql .= ' ORDER BY f.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$heritageFoods = $stmt->fetchAll();

$categories = $pdo->query('SELECT * FROM food_categories ORDER BY name ASC')->fetchAll();
$foodImages = file_exists('food_images.php') ? require 'food_images.php' : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Halal Culinary Heritage Archive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: linear-gradient(180deg, #eef5f1 0%, #f8fbf9 48%, #eef5f1 100%); }
        .archive-card { background: rgba(255,255,255,.86); border: 1px solid rgba(22,59,50,.1); box-shadow: 0 18px 40px rgba(22,59,50,.07); transition: transform .3s ease, box-shadow .3s ease; }
        .archive-card:hover { transform: translateY(-4px); box-shadow: 0 24px 48px rgba(22,59,50,.12); }
        .dish-card[hidden] { display: none !important; }
    </style>
</head>
<body class="text-stone-800 flex flex-col min-h-screen">
    <?php include 'navbar.php'; ?>

    <main class="max-w-7xl mx-auto px-4 py-8 flex-grow">
        <?php if (isset($_SESSION['user_id'])): ?>
            <?php
            $dashLink = 'index.php';
            $userRoleLower = strtolower(trim((string) ($_SESSION['role'] ?? '')));
            if ($userRoleLower === 'customer') $dashLink = 'order.php';
            elseif ($userRoleLower === 'admin') $dashLink = 'admin_dashboard.php';
            elseif ($userRoleLower === 'staff') $dashLink = 'staff_dashboard.php';
            elseif ($userRoleLower === 'supplier') $dashLink = 'suppliers_dashboard.php';
            ?>
            <div class="mb-6 p-4 bg-emerald-100 border border-emerald-300 rounded-xl flex justify-between items-center text-emerald-950">
                <div>
                    <span class="font-bold">Welcome back, <?= h($_SESSION['name'] ?? 'User') ?>!</span>
                    <span class="text-xs ml-2 bg-emerald-800 text-white px-2 py-0.5 rounded uppercase font-semibold"><?= h($_SESSION['role']) ?></span>
                </div>
                <a href="<?= h($dashLink) ?>" class="bg-emerald-800 text-white text-xs px-3 py-1.5 rounded hover:bg-emerald-700">Go to Your Dashboard &rarr;</a>
            </div>
        <?php endif; ?>

        <header class="hh-page-intro relative mb-8 overflow-hidden rounded-3xl border border-emerald-100 px-6 py-12 text-center shadow-sm sm:px-10 max-w-4xl mx-auto">
            <span aria-hidden="true" class="pointer-events-none absolute left-8 right-8 top-0 h-px bg-gradient-to-r from-transparent via-emerald-400/30 to-transparent"></span>
            <p class="text-xs uppercase tracking-[0.24em] text-amber-700 font-bold mb-3">The Heritage Archive</p>
            <h1 class="text-4xl sm:text-5xl text-emerald-950 font-extrabold mb-3">Preserving authentic halal heritage</h1>
            <p class="text-stone-600 max-w-2xl mx-auto">Discover traditional recipes, deep cultural stories, and verified halal origins.</p>
        </header>

        <div class="mb-8 flex flex-wrap gap-4 justify-center">
            <input type="text" id="live-search-input" value="<?= h($search) ?>" placeholder="Type to search dishes, ingredients, or origins..."
                   class="px-4 py-2.5 border border-stone-300 rounded-xl w-80 focus:ring-2 focus:ring-emerald-600 focus:outline-none shadow-sm text-sm">
            <select id="live-category-filter" class="px-4 py-2.5 border border-stone-300 rounded-xl focus:ring-2 focus:ring-emerald-600 focus:outline-none shadow-sm text-sm bg-white">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $catFilter == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="dish-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 items-stretch">
            <?php foreach ($heritageFoods as $food): ?>
                <?php
                $foodImage = $foodImages[$food['name']] ?? 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=800&q=80';
                $origin    = $food['origin'] ?? 'Mindanao, PH';
                ?>
                <div class="archive-card dish-card rounded-2xl overflow-hidden flex flex-col justify-between h-full"
                     data-name="<?= h(strtolower($food['name'])) ?>"
                     data-origin="<?= h(strtolower($origin)) ?>"
                     data-history="<?= h(strtolower($food['history'] ?? '')) ?>"
                     data-ingredients="<?= h(strtolower($food['ingredients'] ?? '')) ?>"
                     data-category="<?= (int) ($food['category_id'] ?? 0) ?>">
                    <div>
                        <div class="relative h-52 w-full overflow-hidden bg-stone-100 flex-shrink-0">
                            <img src="<?= h($foodImage) ?>" alt="<?= h($food['name']) ?>" class="w-full h-full object-cover transition-transform duration-500 hover:scale-105" loading="lazy">
                            <span class="absolute top-3 left-3 text-xs uppercase tracking-wider bg-emerald-900/90 text-amber-400 px-2.5 py-1 rounded-md font-semibold backdrop-blur-sm shadow-sm">
                                <?= h($food['category_name'] ?? 'Halal Dish') ?>
                            </span>
                        </div>
                        <div class="p-6 pb-2">
                            <div class="text-xs text-stone-500 font-medium mb-1">Origin: <?= h($origin) ?></div>
                            <h2 class="text-xl sm:text-2xl font-serif text-emerald-950 font-bold mb-2 leading-snug min-h-[3.5rem] flex items-center"><?= h($food['name']) ?></h2>
                            <p class="text-sm text-stone-600 mb-4 leading-relaxed line-clamp-3 min-h-[4.25rem]"><?= h($food['history'] ?? 'Authentic halal culinary dish rich in culture.') ?></p>
                            <div class="mb-4"><?php renderHalalTraceabilityTag($food); ?></div>
                        </div>
                    </div>
                    <div class="p-6 pt-0 mt-auto">
                        <div class="bg-stone-50 p-3.5 rounded-xl border border-stone-100 text-xs mb-4">
                            <div class="mb-1"><strong class="text-emerald-900">Halal Guarantee:</strong> <?= h($food['halal_info'] ?? '100% Certified Halal') ?></div>
                            <span class="text-stone-500">Supplier: <?= h($food['supplier_name'] ?? 'Local Artisans') ?></span>
                        </div>
                        <a href="food_details.php?id=<?= (int) $food['id'] ?>" class="w-full inline-flex items-center justify-center gap-2 bg-emerald-800 text-white px-4 py-2.5 rounded-xl font-bold hover:bg-emerald-700 transition shadow-sm text-sm">
                            <span>Read Heritage Story</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="no-results" class="hidden text-center py-12">
            <p class="text-lg font-bold text-stone-600">No matching halal dishes found.</p>
            <p class="text-sm text-stone-400 mt-1">Try adjusting your search query.</p>
        </div>
    </main>

    <script>
        const searchInput = document.getElementById('live-search-input');
        const categoryFilter = document.getElementById('live-category-filter');
        const dishCards = document.querySelectorAll('.dish-card');
        const noResults = document.getElementById('no-results');

        function filterDishes() {
            const query = searchInput.value.trim().toLowerCase();
            const selectedCategory = categoryFilter.value;
            let visibleCount = 0;

            dishCards.forEach(card => {
                const matchesSearch = query === ''
                    || (card.dataset.name || '').includes(query)
                    || (card.dataset.origin || '').includes(query)
                    || (card.dataset.history || '').includes(query)
                    || (card.dataset.ingredients || '').includes(query);
                const matchesCategory = selectedCategory === '' || card.dataset.category === selectedCategory;
                const visible = matchesSearch && matchesCategory;
                card.classList.toggle('hidden', !visible);
                if (visible) visibleCount++;
            });

            noResults.classList.toggle('hidden', visibleCount > 0);
        }

        searchInput.addEventListener('input', filterDishes);
        categoryFilter.addEventListener('change', filterDishes);
    </script>
</body>
</html>