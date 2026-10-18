<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$cartCount = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
$userRole  = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$canManageSupplierDirectory = in_array($userRole, ['admin', 'staff'], true);
$isCustomer = $userRole === 'customer';
$isSupplier = $userRole === 'supplier';
$unreadNotificationCount = 0;
if (isset($_SESSION['user_id']) && isset($pdo)) {
    ensureNotificationArchiveColumn($pdo);
    try {
        $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_deleted = 0');
        $unreadStmt->execute([(int) $_SESSION['user_id']]);
        $unreadNotificationCount = (int) $unreadStmt->fetchColumn();
    } catch (PDOException $exception) {
        $unreadNotificationCount = 0;
    }
}
?>
<style>
    @import url("assets/system-theme.css");
    :root { --hh-ink: #163b32; --hh-forest: #205448; --hh-sage: #dcebe3; --hh-mist: #f4f8f5; --hh-gold: #d9a83e; }
    .hh-nav {
        background-color: #163b32;
        background-image: linear-gradient(rgba(22, 59, 50, 0.94), rgba(22, 59, 50, 0.94)), url("assets/system-pattern.jpg");
        background-position: center; background-size: cover; backdrop-filter: blur(16px);
        box-shadow: 0 10px 30px rgba(22, 59, 50, 0.14);
    }
    #navDrawerOverlay { z-index: 1000; }
    #navDrawer { z-index: 1001; }
    .hh-nav-link, .hh-drawer-link { transition: background-color .2s ease, border-color .2s ease, color .2s ease, transform .2s ease, box-shadow .2s ease; }
    .hh-nav-link:hover, .hh-drawer-link:hover { transform: translateY(-1px); }
    .hh-nav-link:active, .hh-drawer-link:active { transform: translateY(0) scale(.98); }
    #navDrawerToggle { position: relative; display: inline-flex; width: 2.75rem; height: 2.75rem; align-items: center; justify-content: center; transition: background-color .2s ease, transform .2s ease, box-shadow .2s ease; }
    #navDrawerToggle:active { transform: scale(.94); }
    .hh-menu-icon { position: absolute; transition: opacity .18s ease, transform .18s ease; }
    .hh-menu-icon-close { opacity: 0; transform: rotate(-90deg) scale(.75); }
    #navDrawerToggle.is-open .hh-menu-icon-menu { opacity: 0; transform: rotate(90deg) scale(.75); }
    #navDrawerToggle.is-open .hh-menu-icon-close { opacity: 1; transform: rotate(0) scale(1); }
    .hh-drawer { background: linear-gradient(180deg, #163b32 0%, #102e28 100%); }
    #navDrawer .hh-drawer-link { color: #fff; }
    #navDrawer .hh-drawer-link:hover, #navDrawer .hh-drawer-link:focus-visible {
        background-color: #fef3c7; border-color: #fcd34d; color: #163b32; outline: none;
    }
    @media (prefers-reduced-motion: reduce) {
        .hh-nav-link, .hh-drawer-link, #navDrawerToggle, .hh-menu-icon, #navDrawer, #navDrawerOverlay { transition-duration: .01ms !important; }
    }
    .hh-page-intro {
        background: radial-gradient(circle at top right, rgba(217, 168, 62, .18), transparent 34%), linear-gradient(135deg, #e8f2ed 0%, #f8fbf9 68%);
    }
</style>

<nav class="hh-nav text-white border-b border-emerald-900 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
        <div class="flex items-center gap-3">
            <button id="navDrawerToggle" type="button" onclick="toggleDrawer()" class="rounded-xl border border-emerald-800 bg-emerald-900 text-stone-100 hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-amber-400" aria-label="Open navigation menu" aria-controls="navDrawer" aria-expanded="false" title="Open menu">
                <svg class="hh-menu-icon hh-menu-icon-menu w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                <svg class="hh-menu-icon hh-menu-icon-close w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
            <?php $brandTone = 'dark'; include 'brand_logo.php'; ?>
        </div>

        <div class="flex items-center gap-2.5 text-xs font-bold">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="profile.php" class="hh-nav-link bg-emerald-900 hover:bg-emerald-800 text-stone-200 px-3.5 py-2 rounded-xl transition flex items-center gap-1.5 border border-emerald-800 shadow-sm">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <span>My Profile</span>
                </a>
                <a href="notifications.php" class="hh-nav-link relative bg-emerald-900 hover:bg-emerald-800 text-stone-200 px-3.5 py-2 rounded-xl transition flex items-center gap-1.5 border border-emerald-800 shadow-sm" aria-label="Notifications<?= $unreadNotificationCount ? ', ' . $unreadNotificationCount . ' unread' : '' ?>">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 01-6 0v-1m6 0H9"></path></svg>
                    <span class="hidden sm:inline">Alerts</span>
                    <span id="unread-notification-count" class="<?= $unreadNotificationCount ? '' : 'hidden' ?> bg-amber-400 text-emerald-950 text-[10px] px-1.5 py-0.5 rounded-full font-extrabold"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span>
                </a>
            <?php endif; ?>

            <?php if ($isCustomer): ?>
                <a href="cart.php" class="hh-nav-link bg-amber-500 hover:bg-amber-400 text-emerald-950 px-3.5 py-2 rounded-xl font-extrabold transition flex items-center gap-1.5 shadow-sm border border-amber-600">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"></path></svg>
                    <span>Cart</span>
                    <?php if ($cartCount > 0): ?>
                        <span class="bg-emerald-950 text-amber-400 text-[10px] px-1.5 py-0.5 rounded-full font-bold ml-0.5"><?= (int) $cartCount ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div id="navDrawerOverlay" onclick="toggleDrawer()" class="fixed inset-0 bg-stone-950/60 backdrop-blur-sm z-50 opacity-0 pointer-events-none transition-opacity duration-300"></div>

<aside id="navDrawer" class="hh-drawer fixed top-0 left-0 h-full w-80 text-white z-50 transform -translate-x-full transition-transform duration-300 ease-in-out shadow-2xl flex flex-col justify-between border-r border-emerald-900">
    <div class="p-5">
        <div class="flex justify-between items-center mb-6 pb-4 border-b border-emerald-900">
            <span class="text-lg font-bold text-amber-400 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"></path></svg>
                <span>Menu &amp; Account</span>
            </span>
            <button type="button" onclick="toggleDrawer()" class="hh-drawer-link text-stone-400 hover:text-white p-2 rounded-xl hover:bg-emerald-900 transition" aria-label="Close navigation menu">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <nav class="flex flex-col gap-2.5">
            <a href="index.php" class="hh-drawer-link p-3 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold transition flex items-center gap-3">
                <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                <span>Home Page</span>
            </a>
            <a href="menu.php" onclick="closeDrawerBeforeNavigation()" class="hh-drawer-link p-3 rounded-xl bg-emerald-900 text-amber-400 font-bold transition flex items-center gap-3 border border-emerald-800">
                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                <span>Food Menu</span>
            </a>
            <a href="halal_suppliers.php" onclick="closeDrawerBeforeNavigation()" class="hh-drawer-link p-3 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold transition flex items-center gap-3">
                <span aria-hidden="true" class="w-5 text-center text-amber-400">&#10003;</span>
                <span><?= $canManageSupplierDirectory ? 'Manage Halal Suppliers' : 'Verified Halal Suppliers' ?></span>
            </a>
            <a href="about.php" onclick="closeDrawerBeforeNavigation()" class="hh-drawer-link p-3 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold transition flex items-center gap-3">
                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3l1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2L12 3z"></path></svg>
                <span>About &amp; Halal Traceability</span>
            </a>

            <?php if ($isCustomer): ?>
                <a href="cart.php" class="hh-drawer-link p-3 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold transition flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"></path></svg>
                        <span>Shopping Cart</span>
                    </div>
                    <?php if ($cartCount > 0): ?>
                        <span class="bg-amber-500 text-emerald-950 text-xs font-extrabold px-2 py-0.5 rounded-full shadow-sm"><?= (int) $cartCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="order.php" class="hh-drawer-link p-3 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold transition flex items-center gap-3">
                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                    <span>My Orders &amp; Status</span>
                </a>
            <?php endif; ?>

            <?php if ($isSupplier): ?>
                <a href="suppliers_dashboard.php" class="hh-drawer-link p-3 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold transition flex items-center gap-3">
                    <span aria-hidden="true" class="w-5 text-center text-amber-400">&#9679;</span>
                    <span>Supplier Dashboard</span>
                </a>
                <a href="add_supplied_food.php" class="hh-drawer-link p-3 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold transition flex items-center gap-3">
                    <span aria-hidden="true" class="w-5 text-center text-amber-400">+</span>
                    <span>Register New Item</span>
                </a>
            <?php endif; ?>

            <?php if (in_array($userRole, ['admin', 'staff'], true)): ?>
                <a href="<?= $userRole === 'admin' ? 'admin_dashboard.php' : 'staff_dashboard.php' ?>" class="hh-drawer-link p-3 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold transition flex items-center gap-3">
                    <span aria-hidden="true" class="w-5 text-center text-amber-400">&#9881;</span>
                    <span><?= $userRole === 'admin' ? 'Admin Dashboard' : 'Staff Dashboard' ?></span>
                </a>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="profile.php" class="hh-drawer-link p-3 rounded-xl hover:bg-emerald-900 text-amber-400 font-bold transition flex items-center gap-3">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <span>Edit Profile Details</span>
                </a>
            <?php endif; ?>
        </nav>
    </div>

    <div class="p-5 border-t border-emerald-900 bg-emerald-900/40">
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="logout.php" class="w-full text-center bg-rose-800 hover:bg-rose-700 text-white font-bold py-3 px-4 rounded-xl text-sm transition flex items-center justify-center gap-2 shadow-sm border border-rose-900">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                <span>Logout Session</span>
            </a>
        <?php else: ?>
            <a href="login.php" class="w-full text-center bg-amber-500 hover:bg-amber-400 text-emerald-950 font-bold py-3 px-4 rounded-xl text-sm transition flex items-center justify-center gap-2 shadow-sm border border-amber-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                <span>Sign In / Register</span>
            </a>
        <?php endif; ?>
    </div>
</aside>

<script>
function toggleDrawer() {
    const drawer = document.getElementById('navDrawer');
    const overlay = document.getElementById('navDrawerOverlay');
    const isOpen = !drawer.classList.contains('-translate-x-full');
    const shouldOpen = !isOpen;
    drawer.classList.toggle('-translate-x-full', !shouldOpen);
    overlay.classList.toggle('opacity-0', !shouldOpen);
    overlay.classList.toggle('pointer-events-none', !shouldOpen);
    const toggle = document.getElementById('navDrawerToggle');
    toggle.classList.toggle('is-open', shouldOpen);
    toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    toggle.setAttribute('aria-label', shouldOpen ? 'Close navigation menu' : 'Open navigation menu');
}
function closeDrawerBeforeNavigation() {
    document.getElementById('navDrawer').classList.add('-translate-x-full');
    document.getElementById('navDrawerOverlay').classList.add('opacity-0', 'pointer-events-none');
    const toggle = document.getElementById('navDrawerToggle');
    toggle.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open navigation menu');
}
</script>
<?php if (isset($_SESSION['user_id'])): ?>
<script>
async function refreshNotificationCount() {
    try {
        const response = await fetch('notification_count.php', { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = await response.json();
        const badge = document.getElementById('unread-notification-count');
        if (!badge) return;
        badge.textContent = data.count > 99 ? '99+' : data.count;
        badge.classList.toggle('hidden', data.count === 0);
    } catch (error) {}
}
window.setInterval(refreshNotificationCount, 30000);
</script>
<?php endif; ?>