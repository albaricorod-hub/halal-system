<?php
require_once 'config.php';
ensureNotificationArchiveColumn($pdo);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$staffAuthStmt = $pdo->prepare('SELECT id, name, role, status FROM users WHERE id = ? LIMIT 1');
$staffAuthStmt->execute([(int) $_SESSION['user_id']]);
$staffAccount = $staffAuthStmt->fetch();

if (!$staffAccount
    || strtolower(trim((string) ($staffAccount['role'] ?? ''))) !== 'staff'
    || strtolower(trim((string) ($staffAccount['status'] ?? ''))) !== 'approved') {
    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['name'], $_SESSION['status']);
    header('Location: login.php?reason=staff_not_approved');
    exit;
}

$_SESSION['role']   = 'staff';
$_SESSION['name']   = $staffAccount['name'];
$_SESSION['status'] = $staffAccount['status'] ?? null;

$staffCsrf = csrfToken('staff_history_csrf');

/* ------------------------------------------------------------------ */
/* Staff notification history (archive flag, not hard-delete)          */
/* ------------------------------------------------------------------ */
$historyMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_staff_history']) || isset($_POST['restore_staff_history']))) {
    requireCsrf('staff_history_csrf');
    $userId = (int) $_SESSION['user_id'];

    if (isset($_POST['delete_staff_history'])) {
        $pdo->prepare('UPDATE notifications SET is_deleted = 1 WHERE user_id = ? AND is_deleted = 0')->execute([$userId]);
        $historyMsg = 'All staff history has been archived. You can restore it any time.';
        recordSystemActivity($pdo, 'Staff notification history archived', 'Staff archived personal dashboard notifications.');
    } else {
        $pdo->prepare('UPDATE notifications SET is_deleted = 0 WHERE user_id = ? AND is_deleted = 1')->execute([$userId]);
        $historyMsg = 'Staff history has been restored.';
        recordSystemActivity($pdo, 'Staff notification history restored', 'Staff restored personal dashboard notifications.');
    }
}

/* ------------------------------------------------------------------ */
/* Aggregates                                                         */
/* ------------------------------------------------------------------ */
try {
    $pendingOrdersCount   = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE LOWER(order_status) = 'pending' AND LOWER(status) = 'pending'")->fetchColumn();
    $preparingOrdersCount = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE LOWER(order_status) = 'preparing' OR (LOWER(order_status) = 'pending' AND LOWER(status) = 'preparing')")->fetchColumn();
    $completedTodayCount  = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE (LOWER(order_status) = 'completed' OR LOWER(status) = 'completed') AND DATE(created_at) = CURDATE()")->fetchColumn();
} catch (Throwable $e) {
    $pendingOrdersCount = $preparingOrdersCount = $completedTodayCount = 0;
}

$allowedFilters = ['all', 'pending', 'confirmed', 'preparing', 'ready', 'out for delivery', 'completed', 'cancelled'];
$statusFilter   = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}

$displayStatusExpr = "LOWER(COALESCE(NULLIF(CASE WHEN LOWER(o.order_status) = 'pending' AND LOWER(o.status) <> 'pending' THEN o.status ELSE NULL END, ''), NULLIF(o.order_status, ''), NULLIF(o.status, ''), 'pending'))";

$query = "SELECT o.id, o.total_amount,
                 CASE WHEN LOWER(o.order_status) = 'pending' AND LOWER(o.status) <> 'pending' THEN o.status
                     ELSE COALESCE(NULLIF(o.order_status, ''), NULLIF(o.status, ''), 'Pending') END AS display_status,
                 COALESCE(NULLIF(o.delivery_address, ''), u.address) AS delivery_address,
                 o.created_at, u.id AS customer_id, u.name AS customer_name, u.contact_number,
                 COALESCE(NULLIF(p.payment_method, ''), NULLIF(o.payment_method, ''), 'COD') AS payment_method,
                 COALESCE(NULLIF(p.payment_status, ''), NULLIF(o.payment_status, ''), 'unpaid') AS payment_status
          FROM orders o
          LEFT JOIN users u ON o.user_id = u.id
          LEFT JOIN payments p ON p.order_id = o.id";
$params = [];
if ($statusFilter !== 'all') {
    $query .= " WHERE {$displayStatusExpr} = ?";
    $params[] = $statusFilter;
}
$query .= ' ORDER BY o.created_at DESC';

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Staff dashboard order query failed: ' . $e->getMessage());
    $orders = [];
}

try {
    $recentNotifications = $pdo->query("SELECT o.id, o.total_amount, o.created_at, u.name AS customer_name
                                        FROM orders o
                                        LEFT JOIN users u ON u.id = o.user_id
                                        WHERE o.created_at >= NOW() - INTERVAL 15 MINUTE
                                        ORDER BY o.created_at DESC")->fetchAll();
} catch (Throwable $e) {
    $recentNotifications = [];
}

$paymentLabel = static function (string $code): string {
    return in_array($code, ['COD', 'Cash on Delivery'], true) ? 'Cash on Delivery' : $code;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Portal - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/system-theme.css">
    <style>
        :root { --staff-sidebar-width: 18rem; --staff-header-height: 4rem; }
        body { background: linear-gradient(180deg, #eef5f1 0%, #f7faf8 100%); overflow-x: hidden; }
        .soft-card { background: rgba(255,255,255,0.8); border: 1px solid rgba(18,59,49,0.08); border-radius: 1rem; box-shadow: 0 16px 35px rgba(18,59,49,0.06); }
        .staff-topbar { height: var(--staff-header-height); }
        .staff-sidebar { width: min(var(--staff-sidebar-width), calc(100vw - 2.5rem)); max-width: calc(100vw - 2.5rem); height: 100vh; height: 100dvh; transform: translateX(-100%); transition: transform .3s cubic-bezier(.22, 1, .36, 1); will-change: transform; }
        .staff-sidebar-content { flex: 1 1 auto; min-height: 0; overflow-x: hidden; overflow-y: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; }
        .staff-sidebar-footer { flex: 0 0 auto; padding-bottom: max(1rem, env(safe-area-inset-bottom)); }
        .staff-sidebar a, .staff-sidebar button { transition: background-color .2s ease, border-color .2s ease, color .2s ease, transform .2s ease, box-shadow .2s ease; }
        .staff-sidebar a:hover, .staff-sidebar button:hover { transform: translateX(3px); }
        .staff-sidebar a:active, .staff-sidebar button:active { transform: translateX(1px) scale(.98); }
        .staff-sidebar a[aria-current="page"] { background-color: #064e3b; border-color: #f59e0b; color: #fcd34d; box-shadow: inset 3px 0 #f59e0b; }
        .staff-sidebar-toggle { position: relative; display: inline-flex; width: 2.5rem; height: 2.5rem; align-items: center; justify-content: center; }
        .sidebar-toggle-icon { position: absolute; transition: opacity .18s ease, transform .18s ease; }
        .sidebar-toggle-icon-close { opacity: 0; transform: rotate(-90deg) scale(.75); }
        .staff-sidebar-toggle.is-open .sidebar-toggle-icon-menu { opacity: 0; transform: rotate(90deg) scale(.75); }
        .staff-sidebar-toggle.is-open .sidebar-toggle-icon-close { opacity: 1; transform: rotate(0) scale(1); }
        #navDrawerOverlay { transition: opacity .25s ease; }
        .staff-main { padding-top: calc(var(--staff-header-height) + 2rem) !important; }
        .staff-orders-table { width: 100%; min-width: 900px; table-layout: fixed; }
        #navDrawerOverlay.is-visible { opacity: 1; pointer-events: auto; }
        body.staff-drawer-open { overflow: hidden; }
        @media (min-width: 1024px) {
            .staff-topbar { left: var(--staff-sidebar-width) !important; right: 0 !important; width: auto !important; }
            .staff-sidebar { transform: translateX(0) !important; }
            .staff-main { width: calc(100% - var(--staff-sidebar-width)) !important; max-width: none !important; margin: 0 0 0 var(--staff-sidebar-width) !important; padding: calc(var(--staff-header-height) + 2rem) clamp(1.25rem, 2.5vw, 2.5rem) 3rem; }
            .staff-sidebar-toggle, .staff-sidebar-close, #navDrawerOverlay { display: none !important; }
        }
        @media (min-width: 1280px) { .staff-orders-table { min-width: 0; } }
        @media (max-width: 1023px) {
            .staff-sidebar { transform: translateX(-100%) !important; }
            .staff-sidebar.is-open { transform: translateX(0) !important; }
            .staff-main { padding-right: 1rem; padding-left: 1rem; }
            .staff-sidebar-content { padding: 1rem; }
            .staff-sidebar-footer { padding: .75rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            .staff-sidebar, .staff-sidebar a, .staff-sidebar button, #navDrawerOverlay, .sidebar-toggle-icon { transition-duration: .01ms !important; }
        }
    </style>
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen flex flex-col font-sans">

    <nav class="staff-topbar fixed inset-x-0 top-0 z-40 bg-emerald-950 text-white border-b border-emerald-900 shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <button id="staff-sidebar-toggle" type="button" onclick="toggleDrawer()" class="staff-sidebar-toggle rounded-xl bg-emerald-900 hover:bg-emerald-800 text-stone-200 transition focus:outline-none focus:ring-2 focus:ring-amber-400 lg:hidden" aria-label="Open staff navigation" aria-controls="navDrawer" aria-expanded="false">
                    <svg class="sidebar-toggle-icon sidebar-toggle-icon-menu w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    <svg class="sidebar-toggle-icon sidebar-toggle-icon-close w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
                <?php $brandTone = 'dark'; $brandHref = 'staff_dashboard.php'; include 'brand_logo.php'; ?>
                <span class="hidden text-sm font-semibold text-emerald-100 sm:inline">Staff Workspace</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-emerald-300 hidden md:inline-flex items-center gap-1.5 text-xs font-bold bg-emerald-900/60 px-3 py-1.5 rounded-lg border border-emerald-800">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <span><?= h($_SESSION['name'] ?? 'Staff Station') ?></span>
                </span>
            </div>
        </div>
    </nav>

    <div id="navDrawerOverlay" onclick="toggleDrawer()" class="fixed inset-0 bg-stone-950/60 backdrop-blur-sm z-50 opacity-0 pointer-events-none transition-opacity duration-300"></div>

    <aside id="navDrawer" class="staff-sidebar fixed top-0 left-0 h-full bg-emerald-950 text-white z-50 shadow-2xl flex flex-col border-r border-emerald-900" aria-label="Staff navigation">
        <div class="staff-sidebar-content p-5">
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-emerald-900">
                <span class="text-lg font-bold text-amber-400">Staff Controls</span>
                <button type="button" onclick="toggleDrawer()" class="staff-sidebar-close text-stone-400 hover:text-white p-2 rounded-xl hover:bg-emerald-900 transition lg:hidden" aria-label="Close staff navigation">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <nav class="flex flex-col gap-3">
                <a href="staff_dashboard.php" aria-current="page" class="w-full p-3.5 rounded-xl text-stone-200 font-bold text-sm transition flex items-center gap-3 border border-transparent hover:bg-emerald-900 hover:border-emerald-800">
                    <svg class="w-5 h-5 shrink-0 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"></path></svg><span>Kitchen Dashboard</span>
                </a>
                <a href="order.php" class="w-full p-3.5 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold text-sm transition flex items-center gap-3 border border-transparent hover:border-emerald-800">
                    <svg class="w-5 h-5 shrink-0 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 7h6m-6 4h6"></path></svg><span>All Orders List</span>
                </a>
                <a href="menu.php" class="w-full p-3.5 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold text-sm transition flex items-center gap-3 border border-transparent hover:border-emerald-800">
                    <svg class="w-5 h-5 shrink-0 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg><span>Food Menu View</span>
                </a>
                <a href="staff_customers.php" class="w-full p-3.5 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold text-sm transition flex items-center gap-3 border border-transparent hover:border-emerald-800">
                    <svg class="w-5 h-5 shrink-0 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-5.5-3.714M9 20H2v-2a4 4 0 017-2.646M16 3.128a4 4 0 010 7.744M8 3.128a4 4 0 000 7.744M12 14a4 4 0 100-8 4 4 0 000 8z"></path></svg><span>Customer Directory</span>
                </a>
                <a href="halal_suppliers.php" class="w-full p-3.5 rounded-xl hover:bg-emerald-900 text-stone-200 font-bold text-sm transition flex items-center gap-3 border border-transparent hover:border-emerald-800">
                    <svg class="w-5 h-5 shrink-0 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 3a11.955 11.955 0 01-8.618 2.984A12.003 12.003 0 003 9c0 5.591 3.824 10.29 9 11.622C17.176 19.29 21 14.591 21 9c0-1.042-.133-2.052-.382-3.016z"></path></svg><span>Manage Halal Suppliers</span>
                </a>
            </nav>
        </div>
        <div class="staff-sidebar-footer p-4 border-t border-emerald-900 bg-emerald-900/40 space-y-2">
            <div class="space-y-1.5">
                <p class="text-[10px] font-bold uppercase tracking-wider text-amber-400/80">History Control</p>
                <form action="staff_dashboard.php" method="POST" class="grid grid-cols-2 gap-2">
                    <input type="hidden" name="csrf_token" value="<?= h($staffCsrf) ?>">
                    <button type="submit" name="delete_staff_history" value="1" onclick="return confirm('Archive your staff notification history?');" class="min-h-9 min-w-0 rounded-lg border border-rose-900 bg-rose-700 px-2 py-2 text-[10px] font-bold leading-tight text-white shadow-sm transition hover:bg-rose-600"><svg class="mx-auto mb-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3m-9 0h14"></path></svg>Archive history</button>
                    <button type="submit" name="restore_staff_history" value="1" class="min-h-9 min-w-0 rounded-lg border border-amber-600 bg-amber-500 px-2 py-2 text-[10px] font-bold leading-tight text-emerald-950 shadow-sm transition hover:bg-amber-400"><svg class="mx-auto mb-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h6m0 0V4m0 6l-2.5-2.5A7 7 0 1117 6"></path></svg>Restore history</button>
                </form>
            </div>
            <a href="logout.php" class="flex min-h-9 w-full items-center justify-center gap-2 rounded-lg border border-rose-900 bg-rose-800 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-rose-700"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>Logout Session</a>
        </div>
    </aside>

    <main class="staff-main max-w-7xl mx-auto px-4 py-8 flex-grow w-full">
        <header class="relative mb-6 flex flex-col justify-between gap-4 overflow-hidden border-b border-stone-300 pb-4 md:flex-row md:items-center">
            <div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-emerald-950 flex items-center gap-3">
                    <svg class="w-8 h-8 text-emerald-800 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                    <span>Kitchen Dispatch &amp; Order Controls</span>
                </h1>
                <p class="text-stone-600 text-sm mt-1">Manage kitchen dispatch tasks and process incoming web orders.</p>
            </div>
        </header>

        <?php if ($historyMsg !== ''): ?>
            <div class="mb-6 p-4 bg-amber-100 text-amber-800 text-xs rounded-xl border border-amber-300 font-bold"><?= h($historyMsg) ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
            <a href="staff_dashboard.php?status=pending" class="soft-card p-5 hover:border-amber-400 transition flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase text-stone-500 tracking-wider">Pending Orders</span>
                    <h3 class="text-2xl font-extrabold text-amber-600 mt-1"><?= $pendingOrdersCount ?></h3>
                </div>
            </a>
            <a href="staff_dashboard.php?status=preparing" class="soft-card p-5 hover:border-emerald-500 transition flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase text-stone-500 tracking-wider">In Preparation</span>
                    <h3 class="text-2xl font-extrabold text-emerald-700 mt-1"><?= $preparingOrdersCount ?></h3>
                </div>
            </a>
            <a href="staff_dashboard.php?status=completed" class="soft-card p-5 hover:border-blue-400 transition flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase text-stone-500 tracking-wider">Completed Today</span>
                    <h3 class="text-2xl font-extrabold text-stone-800 mt-1"><?= $completedTodayCount ?></h3>
                </div>
            </a>
        </div>

        <div class="w-full">
            <section class="soft-card p-5">
                <div class="flex items-center justify-between pb-3 mb-4 border-b border-stone-200">
                    <h2 class="text-base font-bold text-emerald-950">Dashboard Alerts</h2>
                    <span class="text-[10px] bg-emerald-100 text-emerald-900 border border-emerald-200 px-2 py-0.5 rounded-full font-extrabold">Last 15 minutes</span>
                </div>
                <?php if (empty($recentNotifications)): ?>
                    <p class="p-6 text-center text-xs font-semibold text-stone-500">No recent order notifications.</p>
                <?php else: ?>
                    <div class="space-y-3 max-h-[420px] overflow-y-auto pr-1">
                        <?php foreach ($recentNotifications as $notif): ?>
                            <a href="order_tracker.php?id=<?= (int) $notif['id'] ?>" class="p-3 bg-amber-50 border-l-4 border-amber-500 rounded-r-xl shadow-sm text-xs flex justify-between items-start hover:bg-amber-100 transition">
                                <div>
                                    <p class="font-extrabold text-amber-950">New Order #ORD-<?= (int) $notif['id'] ?></p>
                                    <p class="text-stone-600 mt-0.5">Order Received on Web Portal</p>
                                    <p class="text-stone-500 text-[10px] mt-0.5"><?= date('h:i A', strtotime($notif['created_at'])) ?></p>
                                </div>
                                <span class="font-extrabold text-amber-800 bg-amber-100 px-2 py-0.5 rounded text-[11px]">₱<?= number_format((float) $notif['total_amount'], 2) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="w-full mt-6">
            <section class="soft-card p-5 sm:p-6">
                <div class="flex flex-col gap-4 pb-4 mb-4 border-b border-stone-200">
                    <h2 class="text-lg font-bold text-emerald-950">Incoming Orders Queue</h2>
                    <div class="flex flex-wrap gap-1.5 rounded-xl border border-stone-200 bg-stone-100 p-1.5 text-xs font-bold" role="group" aria-label="Filter orders by status">
                        <?php
                        $filters = [
                            'all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed',
                            'preparing' => 'Preparing', 'ready' => 'Ready',
                            'out for delivery' => 'Out for delivery',
                            'completed' => 'Completed', 'cancelled' => 'Cancelled',
                        ];
                        foreach ($filters as $key => $label):
                            $activeClass = match ($key) {
                                'all' => 'bg-emerald-900 text-white shadow-sm',
                                'pending', 'confirmed' => 'bg-amber-500 text-emerald-950 shadow-sm',
                                'out for delivery' => 'bg-blue-600 text-white shadow-sm',
                                'completed' => 'bg-emerald-700 text-white shadow-sm',
                                'cancelled' => 'bg-rose-700 text-white shadow-sm',
                                default => 'bg-emerald-800 text-white shadow-sm',
                            };
                        ?>
                            <a href="staff_dashboard.php?status=<?= urlencode($key) ?>" class="whitespace-nowrap rounded-lg px-3 py-2 transition <?= $statusFilter === $key ? $activeClass : 'text-stone-600 hover:bg-white hover:text-stone-900' ?>"><?= h($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (empty($orders)): ?>
                    <div class="p-12 text-center text-stone-500">
                        <p class="text-sm font-semibold">No active orders under this filter.</p>
                    </div>
                <?php else: ?>
                    <div class="w-full min-w-0 overflow-x-auto">
                        <table class="staff-orders-table text-left text-sm text-stone-700">
                            <colgroup>
                                <col style="width: 9%">
                                <col style="width: 15%">
                                <col style="width: 20%">
                                <col style="width: 10%">
                                <col style="width: 15%">
                                <col style="width: 13%">
                                <col style="width: 18%">
                            </colgroup>
                            <thead class="bg-stone-50 uppercase text-xs text-stone-500 font-bold border-b border-stone-200">
                                <tr>
                                    <th class="px-3 py-3">Order #</th>
                                    <th class="px-3 py-3">Customer</th>
                                    <th class="px-3 py-3">Delivery address</th>
                                    <th class="px-3 py-3">Total</th>
                                    <th class="px-3 py-3">Payment</th>
                                    <th class="px-3 py-3">Status</th>
                                    <th class="px-3 py-3 text-right">Order action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-200">
                                <?php foreach ($orders as $order): ?>
                                    <?php $orderStatus = strtolower(trim((string) $order['display_status'])); ?>
                                    <tr class="hover:bg-stone-50 transition">
                                        <td class="break-words px-3 py-3 font-bold text-emerald-950">#ORD-<?= (int) $order['id'] ?></td>
                                        <td class="break-words px-3 py-3 font-semibold text-stone-700">
                                            <?= h($order['customer_name'] ?? 'Customer') ?>
                                            <span class="block text-xs font-normal text-stone-500"><?= h($order['contact_number'] ?? '') ?></span>
                                        </td>
                                        <td class="break-words px-3 py-3 text-xs text-stone-600"><?= h($order['delivery_address'] ?? 'No address provided') ?></td>
                                        <td class="whitespace-nowrap px-3 py-3 font-extrabold text-stone-900">₱<?= number_format((float) $order['total_amount'], 2) ?></td>
                                        <td class="break-words px-3 py-3 text-xs">
                                            <span class="block font-semibold"><?= h($paymentLabel((string) $order['payment_method'])) ?></span>
                                            <span class="break-words text-stone-500"><?= h($order['payment_status'] ?? 'unpaid') ?></span>
                                        </td>
                                        <td class="break-words px-3 py-3">
                                            <span class="inline-block max-w-full break-words rounded-md border px-2 py-1 text-xs font-bold uppercase <?= $orderStatus === 'pending' ? 'bg-amber-100 text-amber-800 border-amber-300' : ($orderStatus === 'preparing' ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : ($orderStatus === 'out for delivery' ? 'bg-blue-100 text-blue-800 border-blue-300' : ($orderStatus === 'cancelled' ? 'bg-rose-100 text-rose-800 border-rose-300' : 'bg-stone-100 text-stone-700 border-stone-300'))) ?>">
                                                <?= h($order['display_status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-3 text-right">
                                            <a href="order_tracker.php?id=<?= (int) $order['id'] ?>" aria-label="Open order <?= (int) $order['id'] ?>" class="whitespace-nowrap bg-emerald-800 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg font-bold transition text-xs shadow-sm inline-flex items-center justify-center gap-1.5 border border-emerald-900">
                                                <span>Open order</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        function isStaffSidebarMobile() { return window.matchMedia('(max-width: 1023px)').matches; }
        function setStaffSidebarOpen(isOpen) {
            const drawer = document.getElementById('navDrawer');
            const overlay = document.getElementById('navDrawerOverlay');
            const toggle = document.getElementById('staff-sidebar-toggle');
            drawer.classList.toggle('is-open', isOpen);
            overlay.classList.toggle('is-visible', isOpen);
            toggle.classList.toggle('is-open', isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', isOpen ? 'Close staff navigation' : 'Open staff navigation');
            document.body.classList.toggle('staff-drawer-open', isOpen);
        }
        function toggleDrawer() {
            if (!isStaffSidebarMobile()) return;
            const drawer = document.getElementById('navDrawer');
            setStaffSidebarOpen(!drawer.classList.contains('is-open'));
        }
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && document.getElementById('navDrawer').classList.contains('is-open')) {
                setStaffSidebarOpen(false);
                document.getElementById('staff-sidebar-toggle').focus();
            }
        });
        window.addEventListener('resize', () => {
            if (!isStaffSidebarMobile()) setStaffSidebarOpen(false);
        });
    </script>
</body>
</html>