<?php
require_once 'config.php';
checkAuth(['admin']);

ensureNotificationArchiveColumn($pdo);
$adminCsrf = csrfToken('admin_action_csrf');

$staffMsg = '';
$profileMsg = '';
$historyMsg = '';
$notifMsg = '';
$notificationMsg = '';

/* ------------------------------------------------------------------ */
/* Staff access approval                                              */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_access_action'])) {
    requireCsrf('admin_action_csrf');
    $staffId    = filter_var($_POST['staff_id'] ?? 0, FILTER_VALIDATE_INT);
    $staffAction = (string) $_POST['staff_access_action'];
    $newStaffStatus = $staffAction === 'approve' ? 'approved' : ($staffAction === 'disable' ? 'inactive' : '');

    if ($staffId && $newStaffStatus !== '') {
        $staffStmt = $pdo->prepare("SELECT name FROM users WHERE id = ? AND LOWER(role) = 'staff'");
        $staffStmt->execute([$staffId]);
        $staffName = $staffStmt->fetchColumn();
        if ($staffName !== false) {
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND LOWER(role) = 'staff'")->execute([$newStaffStatus, $staffId]);
            $eventTitle   = $newStaffStatus === 'approved' ? 'Staff access approved' : 'Staff access disabled';
            $eventMessage = $newStaffStatus === 'approved'
                ? 'Your staff account is approved. You can now sign in to the staff workspace.'
                : 'Your staff account access has been disabled. Contact an administrator if you need help.';
            createNotification($pdo, $staffId, $eventTitle, $eventMessage);
            recordSystemActivity($pdo, $eventTitle, "Staff account #{$staffId} ({$staffName}) status changed to {$newStaffStatus}.");
            $staffMsg = "{$staffName}'s staff access was {$newStaffStatus}.";
        } else {
            $staffMsg = 'Select a valid staff account.';
        }
    } else {
        $staffMsg = 'Select a valid staff account action.';
    }
}

/* ------------------------------------------------------------------ */
/* Admin profile update                                               */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin_profile'])) {
    requireCsrf('admin_action_csrf');
    $newName     = trim((string) ($_POST['admin_name'] ?? ''));
    $newEmail    = trim((string) ($_POST['admin_email'] ?? ''));
    $newPassword = (string) ($_POST['admin_password'] ?? '');
    $adminId     = (int) ($_SESSION['user_id'] ?? 0);

    if ($adminId && $newName !== '' && $newEmail !== '') {
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $profileMsg = 'Enter a valid email address.';
        } else {
            $dup = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
            $dup->execute([$newEmail, $adminId]);
            if ($dup->fetch()) {
                $profileMsg = 'That email address is already used by another account.';
            } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
                $profileMsg = 'New password must be at least 8 characters.';
            } else {
                try {
                    if ($newPassword !== '') {
                        $pdo->prepare('UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?')
                            ->execute([$newName, $newEmail, password_hash($newPassword, PASSWORD_BCRYPT), $adminId]);
                    } else {
                        $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
                            ->execute([$newName, $newEmail, $adminId]);
                    }
                    $_SESSION['name']  = $newName;
                    $_SESSION['email'] = $newEmail;
                    $profileMsg = 'Profile updated successfully.';
                } catch (Throwable $e) {
                    error_log('Admin profile update failed: ' . $e->getMessage());
                    $profileMsg = 'Profile update failed. Please try again.';
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* History control                                                    */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_history'])) {
    requireCsrf('admin_action_csrf');
    $pdo->exec('UPDATE notifications SET is_deleted = 1 WHERE is_deleted = 0');
    $historyMsg = 'All notification history was moved to the deleted archive.';
    recordSystemActivity($pdo, 'Notification history archived', 'Admin archived all customer and staff notifications.');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_history'])) {
    requireCsrf('admin_action_csrf');
    $pdo->exec('UPDATE notifications SET is_deleted = 0 WHERE is_deleted = 1');
    $historyMsg = 'All archived notifications have been restored.';
    recordSystemActivity($pdo, 'Notification history restored', 'Admin restored all archived customer and staff notifications.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notification_action'])) {
    requireCsrf('admin_action_csrf');
    $notificationId     = filter_var($_POST['notification_id'] ?? 0, FILTER_VALIDATE_INT);
    $notificationAction = (string) $_POST['notification_action'];
    if ($notificationId && in_array($notificationAction, ['delete', 'restore'], true)) {
        $deletedStatus = $notificationAction === 'delete' ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE notifications SET is_deleted = ? WHERE id = ?');
        $stmt->execute([$deletedStatus, $notificationId]);
        if ($stmt->rowCount() > 0) {
            $notificationMsg = $deletedStatus ? 'Notification moved to the deleted archive.' : 'Notification restored.';
            recordSystemActivity($pdo, $deletedStatus ? 'Notification archived' : 'Notification restored', "Admin changed notification #{$notificationId}.");
        } else {
            $notificationMsg = 'That notification was not found or already had that status.';
        }
    } else {
        $notificationMsg = 'Select a valid notification action.';
    }
}

/* ------------------------------------------------------------------ */
/* Broadcast notifications                                            */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_admin_notification'])) {
    requireCsrf('admin_action_csrf');
    $targetUser = (string) ($_POST['target_user_id'] ?? 'all');
    $message    = trim((string) ($_POST['notification_message'] ?? ''));

    if ($message !== '') {
        if ($targetUser === 'all') {
            $customers = $pdo->query("SELECT id FROM users WHERE role = 'customer'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($customers as $cId) {
                createNotification($pdo, (int) $cId, 'Message from Halal Heritage', $message);
            }
            $notifMsg = 'Notification broadcasted to all registered customers!';
            recordSystemActivity($pdo, 'Customer notification broadcast', 'Recipients: ' . count($customers));
        } else {
            $targetId = filter_var($targetUser, FILTER_VALIDATE_INT);
            $customerStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND LOWER(role) = 'customer' LIMIT 1");
            $customerStmt->execute([$targetId ?: 0]);
            $customerId = $customerStmt->fetchColumn();
            if ($customerId) {
                createNotification($pdo, (int) $customerId, 'Message from Halal Heritage', $message);
                $notifMsg = "Notification sent to customer #{$customerId}!";
                recordSystemActivity($pdo, 'Customer notification sent', "Recipient: customer #{$customerId}");
            } else {
                $notifMsg = 'Select a valid registered customer.';
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Load data                                                          */
/* ------------------------------------------------------------------ */
$totalUsers   = (int) ($pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn() ?: 0);
$totalOrders  = (int) ($pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() ?: 0);
$totalRevenue = 0.0;
try {
    $totalRevenue = (float) ($pdo->query("SELECT SUM(total_amount) FROM orders WHERE LOWER(order_status) = 'completed' OR LOWER(status) = 'completed'")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $totalRevenue = 0.0;
}

$customers      = $pdo->query("SELECT id, name, email, contact_number, address, role, status, created_at FROM users WHERE LOWER(role) = 'customer' ORDER BY created_at DESC")->fetchAll();
$staffAccounts  = $pdo->query("SELECT id, name, email, status, created_at FROM users WHERE LOWER(role) = 'staff' ORDER BY FIELD(status, 'pending', 'approved', 'inactive'), created_at DESC")->fetchAll();

$showDeletedNotifications = ($_GET['notifications'] ?? '') === 'deleted';
$notificationFilter = $showDeletedNotifications ? 1 : 0;
$notificationStmt = $pdo->prepare('SELECT n.*, u.name, u.email FROM notifications n JOIN users u ON n.user_id = u.id WHERE n.is_deleted = ? ORDER BY n.created_at DESC LIMIT 50');
$notificationStmt->execute([$notificationFilter]);
$allNotifications = $notificationStmt->fetchAll();

$ordersList = $pdo->query("SELECT o.*, u.name, p.payment_method, p.payment_status,
                                  CASE WHEN LOWER(o.order_status) = 'pending' AND LOWER(o.status) <> 'pending' THEN o.status
                                       ELSE COALESCE(NULLIF(o.order_status, ''), NULLIF(o.status, ''), 'Pending') END AS display_status
                           FROM orders o
                           JOIN users u ON o.user_id = u.id
                           LEFT JOIN payments p ON p.order_id = o.id
                           ORDER BY o.created_at DESC LIMIT 10")->fetchAll();

$allowedSections = ['orders', 'notifications', 'logs', 'customers', 'staff'];
$initialSection = in_array($_GET['section'] ?? '', $allowedSections, true)
    ? $_GET['section']
    : ($staffMsg !== '' ? 'staff' : ($notificationMsg !== '' || $historyMsg !== '' ? 'logs' : ($notifMsg !== '' ? 'notifications' : 'orders')));

$paymentLabel = static function (string $code): string {
    return in_array($code, ['COD', 'Cash on Delivery'], true) ? 'Cash on Delivery' : $code;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Control Center - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/system-theme.css">
    <style>
        :root { --admin-sidebar-width: 18rem; --admin-header-height: 4.25rem; }
        @media print { .no-print { display: none !important; } }
        body { background: linear-gradient(180deg, #eef5f2 0%, #f7faf8 100%); overflow-x: hidden; }
        .admin-topbar { height: var(--admin-header-height); }
        .admin-sidebar { width: var(--admin-sidebar-width); height: 100vh; height: 100dvh; transform: translateX(-100%); transition: transform 0.3s cubic-bezier(.22, 1, .36, 1); will-change: transform; }
        .admin-sidebar-content { min-height: 0; overflow-y: auto; }
        .admin-sidebar-footer { flex: 0 0 auto; }
        .admin-sidebar a, .admin-sidebar button { transition: background-color .2s ease, border-color .2s ease, color .2s ease, transform .2s ease, box-shadow .2s ease; }
        .admin-sidebar a:hover, .admin-sidebar button:hover { transform: translateX(3px); }
        .admin-sidebar a:active, .admin-sidebar button:active { transform: translateX(1px) scale(.98); }
        .admin-sidebar [data-admin-section].is-active { background-color: #064e3b; color: #fcd34d; box-shadow: inset 3px 0 #f59e0b; }
        .admin-sidebar-toggle { position: relative; display: inline-flex; width: 2.5rem; height: 2.5rem; align-items: center; justify-content: center; }
        .sidebar-toggle-icon { position: absolute; transition: opacity .18s ease, transform .18s ease; }
        .sidebar-toggle-icon-close { opacity: 0; transform: rotate(-90deg) scale(.75); }
        .admin-sidebar-toggle.is-open .sidebar-toggle-icon-menu { opacity: 0; transform: rotate(90deg) scale(.75); }
        .admin-sidebar-toggle.is-open .sidebar-toggle-icon-close { opacity: 1; transform: rotate(0) scale(1); }
        #drawer-overlay { opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .25s ease, visibility 0s linear .25s; }
        #drawer-overlay.is-visible { opacity: 1; visibility: visible; pointer-events: auto; transition-delay: 0s, 0s; }
        .dashboard-shell { max-width: 1320px; margin: 0 auto; padding: 2rem 1rem 3rem; }
        .admin-main { padding-top: calc(var(--admin-header-height) + 2rem); }
        .section-panel { background: rgba(255,255,255,0.7); backdrop-filter: blur(2px); border: 1px solid rgba(18,59,49,0.08); border-radius: 1.5rem; box-shadow: 0 18px 40px rgba(18,59,49,0.08); }
        .dashboard-section[hidden], .dashboard-overview[hidden], [data-message-section][hidden] { display: none !important; }
        @media (min-width: 1024px) {
            .admin-topbar { left: var(--admin-sidebar-width) !important; right: 0 !important; width: auto !important; }
            .admin-sidebar { transform: translateX(0) !important; }
            .admin-main { width: calc(100% - var(--admin-sidebar-width)) !important; max-width: none; margin: 0 0 0 var(--admin-sidebar-width); padding: calc(var(--admin-header-height) + 2rem) clamp(1.5rem, 3vw, 3rem) 3rem; }
            .admin-sidebar-toggle, .admin-sidebar-close, #drawer-overlay { display: none !important; }
        }
        @media (max-width: 1023px) {
            .admin-sidebar { transform: translateX(-100%) !important; }
            .admin-sidebar.is-open { transform: translateX(0) !important; }
            .dashboard-shell { padding-right: 1rem; padding-left: 1rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            .admin-sidebar, .admin-sidebar a, .admin-sidebar button, #drawer-overlay, .sidebar-toggle-icon { transition-duration: .01ms !important; }
        }
        .surface-card { background: rgba(255,255,255,0.88); border: 1px solid rgba(18,59,49,0.08); border-radius: 1.4rem; box-shadow: 0 12px 25px rgba(18,59,49,0.06); }
    </style>
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen flex flex-col font-sans">

    <header class="admin-topbar fixed inset-x-0 top-0 z-30 bg-emerald-950 text-white shadow-md no-print">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <button id="admin-sidebar-toggle" type="button" onclick="toggleDrawer()" aria-controls="admin-drawer" aria-expanded="false" aria-label="Open admin navigation"
                        class="admin-sidebar-toggle rounded-lg bg-emerald-900 text-amber-400 hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-amber-400 transition lg:hidden">
                    <svg class="sidebar-toggle-icon sidebar-toggle-icon-menu w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    <svg class="sidebar-toggle-icon sidebar-toggle-icon-close w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
                <?php $brandTone = 'dark'; include 'brand_logo.php'; ?>
                <span class="bg-amber-500 text-emerald-950 text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-full">Admin Panel</span>
            </div>
        </div>
    </header>

    <div id="drawer-overlay" onclick="toggleDrawer()" class="fixed inset-0 bg-stone-900/50 z-40"></div>

    <aside id="admin-drawer" class="admin-sidebar fixed top-0 left-0 h-full bg-emerald-950 text-white z-50 shadow-2xl flex flex-col no-print" aria-label="Admin navigation">
        <div class="admin-sidebar-content p-5 space-y-6">
            <div class="flex justify-between items-center border-b border-emerald-800 pb-4">
                <span class="font-bold text-amber-400 text-lg">Admin Actions</span>
                <button type="button" onclick="toggleDrawer()" class="admin-sidebar-close text-stone-400 hover:text-white lg:hidden" aria-label="Close admin navigation">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="space-y-2">
                <p class="text-[11px] font-bold uppercase tracking-wider text-amber-400/80">Account Control</p>
                <button type="button" onclick="openModal('modal-admin-profile')" class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-emerald-900 text-xs font-medium text-stone-200 transition">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <span>Admin Profile</span>
                </button>
            </div>

            <div class="pt-2 border-t border-emerald-800 space-y-2">
                <p class="text-[11px] font-bold uppercase tracking-wider text-amber-400/80">Dashboard Sections</p>
                <button type="button" data-admin-section="orders" class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg border border-transparent hover:bg-emerald-900 text-xs font-medium text-stone-200 transition" aria-pressed="true">
                    <svg class="w-4 h-4 shrink-0 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg><span>Order List</span>
                </button>
                <button type="button" data-admin-section="notifications" class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg border border-transparent hover:bg-emerald-900 text-xs font-medium text-stone-200 transition" aria-pressed="false">
                    <svg class="w-4 h-4 shrink-0 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 01-6 0v-1m6 0H9"></path></svg><span>Send Notification</span>
                </button>
                <button type="button" data-admin-section="logs" class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg border border-transparent hover:bg-emerald-900 text-xs font-medium text-stone-200 transition" aria-pressed="false">
                    <svg class="w-4 h-4 shrink-0 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path></svg><span>Notification Logs</span>
                </button>
                <button type="button" data-admin-section="customers" class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg border border-transparent hover:bg-emerald-900 text-xs font-medium text-stone-200 transition" aria-pressed="false">
                    <svg class="w-4 h-4 shrink-0 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-5.5-3.714M9 20H2v-2a4 4 0 017-2.646M16 3.128a4 4 0 010 7.744M8 3.128a4 4 0 000 7.744M12 14a4 4 0 100-8 4 4 0 000 8z"></path></svg><span>Customer Directory</span>
                </button>
                <button type="button" data-admin-section="staff" class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg border border-transparent hover:bg-emerald-900 text-xs font-medium text-stone-200 transition" aria-pressed="false">
                    <svg class="w-4 h-4 shrink-0 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span>Staff Access (<?= count(array_filter($staffAccounts, static fn($s) => $s['status'] === 'pending')) ?> pending)</span>
                </button>
                <a href="halal_suppliers.php" class="w-full text-left flex items-center gap-3 px-3 py-2 rounded-lg border border-transparent hover:bg-emerald-900 text-xs font-medium text-stone-200 transition">
                    <svg class="w-4 h-4 shrink-0 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 3a11.955 11.955 0 01-8.618 2.984A12.003 12.003 0 003 9c0 5.591 3.824 10.29 9 11.622C17.176 19.29 21 14.591 21 9c0-1.042-.133-2.052-.382-3.016z"></path></svg><span>Manage Halal Suppliers</span>
                </a>
            </div>

            <div class="pt-2 border-t border-emerald-800 space-y-2">
                <a href="export_customers.php" class="w-full bg-amber-500 hover:bg-amber-400 text-emerald-950 font-bold px-3 py-2.5 rounded-xl text-xs flex items-center justify-center gap-2 transition shadow-sm"><svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2v-3m14 3v-3M7 3h7l5 5v2M7 3v7"></path></svg>Export Customers (CSV)</a>
                <a href="activity_log.php" class="flex min-h-10 w-full items-center justify-center gap-2 rounded-xl border border-emerald-700 px-3 py-2.5 text-center text-xs font-bold text-stone-100 transition hover:bg-emerald-900"><svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>View System Audit Trail</a>
            </div>
        </div>

        <div class="admin-sidebar-footer p-4 border-t border-emerald-900 space-y-2 bg-emerald-950/90">
            <form action="admin_dashboard.php?section=logs&amp;notifications=deleted" method="POST" class="grid grid-cols-2 gap-2">
                <input type="hidden" name="csrf_token" value="<?= h($adminCsrf) ?>">
                <button type="submit" name="delete_history" value="1" onclick="return confirm('Move all notifications to the deleted archive?');" class="min-h-9 min-w-0 rounded-lg bg-rose-700 px-2 py-2 text-[10px] font-bold leading-tight text-white shadow-sm transition hover:bg-rose-600"><svg class="mx-auto mb-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3m-9 0h14"></path></svg>Archive all</button>
                <button type="submit" name="restore_history" value="1" class="min-h-9 min-w-0 rounded-lg bg-amber-500 px-2 py-2 text-[10px] font-bold leading-tight text-emerald-950 shadow-sm transition hover:bg-amber-400"><svg class="mx-auto mb-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h6m0 0V4m0 6l-2.5-2.5A7 7 0 1117 6"></path></svg>Restore history</button>
            </form>
            <a href="logout.php" class="flex min-h-9 w-full items-center justify-center gap-2 rounded-lg bg-rose-700 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-rose-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>Logout Admin Session</a>
            <p class="text-center text-[10px] text-stone-400">Halal Heritage Admin Panel &copy; <?= date('Y') ?></p>
        </div>
    </aside>

    <main class="dashboard-shell admin-main flex-grow w-full space-y-8">
        <div class="dashboard-overview relative flex flex-col items-start justify-between gap-4 overflow-hidden border-b border-emerald-900/10 pb-4 md:flex-row md:items-center">
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-emerald-950">Admin System Control Center</h1>
                <p class="text-xs text-stone-500 mt-1">Manage orders, payments, notifications, and customer data.</p>
            </div>
        </div>

        <?php if ($profileMsg !== ''): ?><div data-message-section="orders" class="p-4 bg-emerald-100 text-emerald-800 text-xs rounded-xl border border-emerald-300 font-bold"><?= h($profileMsg) ?></div><?php endif; ?>
        <?php if ($notifMsg !== ''): ?><div data-message-section="notifications" class="p-4 bg-emerald-100 text-emerald-800 text-xs rounded-xl border border-emerald-300 font-bold"><?= h($notifMsg) ?></div><?php endif; ?>
        <?php if ($historyMsg !== ''): ?><div data-message-section="logs" class="p-4 bg-amber-100 text-amber-800 text-xs rounded-xl border border-amber-300 font-bold"><?= h($historyMsg) ?></div><?php endif; ?>
        <?php if ($notificationMsg !== ''): ?><div data-message-section="logs" role="status" class="p-4 bg-emerald-100 text-emerald-800 text-xs rounded-xl border border-emerald-300 font-bold"><?= h($notificationMsg) ?></div><?php endif; ?>
        <?php if ($staffMsg !== ''): ?><div data-message-section="staff" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-bold text-emerald-900"><?= h($staffMsg) ?></div><?php endif; ?>

        <div class="dashboard-overview grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
            <div class="bg-white p-5 rounded-2xl border border-stone-200 shadow-sm">
                <span class="text-xs text-stone-500 font-bold uppercase tracking-wider">Registered Customers</span>
                <div class="text-3xl font-extrabold text-emerald-950 mt-1"><?= count($customers) ?></div>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-stone-200 shadow-sm">
                <span class="text-xs text-stone-500 font-bold uppercase tracking-wider">Total Orders Placed</span>
                <div class="text-3xl font-extrabold text-emerald-950 mt-1"><?= $totalOrders ?></div>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-stone-200 shadow-sm">
                <span class="text-xs text-stone-500 font-bold uppercase tracking-wider">Gross Revenue</span>
                <div class="text-3xl font-extrabold text-emerald-800 mt-1">₱<?= number_format($totalRevenue, 2) ?></div>
            </div>
        </div>

        <div id="orders-section" class="dashboard-section bg-white rounded-2xl border border-stone-200 shadow-sm p-4 sm:p-6 space-y-4" <?= $initialSection === 'orders' ? '' : 'hidden' ?>>
            <div class="flex flex-col sm:flex-row justify-between sm:items-center border-b pb-4 gap-2">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-emerald-950">Order List, Payments &amp; Receipts</h2>
                    <p class="text-xs text-stone-500">View real-time orders, payment methods, transaction status, and print receipts.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-stone-50 text-stone-500 uppercase font-bold border-b">
                        <tr>
                            <th class="p-3">Order ID</th>
                            <th class="p-3">Customer</th>
                            <th class="p-3">Total Amount</th>
                            <th class="p-3">Payment Method</th>
                            <th class="p-3">Payment Status</th>
                            <th class="p-3">Order Status</th>
                            <th class="p-3">Actions / Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        <?php if (empty($ordersList)): ?>
                            <tr><td colspan="7" class="p-4 text-center text-stone-500">No orders logged yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($ordersList as $ord): ?>
                                <tr class="hover:bg-stone-50">
                                    <td class="p-3 font-bold text-emerald-950">#ORD-<?= (int) $ord['id'] ?></td>
                                    <td class="p-3 font-medium"><?= h($ord['name']) ?></td>
                                    <td class="p-3 font-bold text-emerald-900">₱<?= number_format((float) ($ord['total_amount'] ?? 0), 2) ?></td>
                                    <td class="p-3"><?= h($paymentLabel((string) ($ord['payment_method'] ?? 'COD'))) ?></td>
                                    <td class="p-3">
                                        <span class="px-2 py-0.5 rounded-full font-bold uppercase text-[10px] <?= in_array(strtolower((string) ($ord['payment_status'] ?? '')), ['paid', 'confirmed'], true) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>">
                                            <?= h($ord['payment_status'] ?? 'unpaid') ?>
                                        </span>
                                    </td>
                                    <td class="p-3 font-bold text-amber-600 uppercase"><?= h($ord['display_status'] ?? 'Pending') ?></td>
                                    <td class="p-3">
                                        <button type="button"
                                                data-ord-id="<?= (int) $ord['id'] ?>"
                                                data-ord-name="<?= h($ord['name']) ?>"
                                                data-ord-total="<?= number_format((float) ($ord['total_amount'] ?? 0), 2) ?>"
                                                onclick="triggerReceiptPrint(this.dataset.ordId, this.dataset.ordName, this.dataset.ordTotal)"
                                                class="bg-emerald-800 hover:bg-emerald-700 text-white px-2.5 py-1 rounded text-[11px] font-bold inline-block">
                                            View Receipt &amp; Print
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="send-notification-section" class="dashboard-section section-panel p-4 sm:p-6" hidden>
            <div class="bg-emerald-950 text-white rounded-2xl p-6 space-y-4 max-w-2xl mx-auto">
                <h2 class="text-base font-bold text-amber-400 uppercase tracking-wider">Send Notification</h2>
                <form action="admin_dashboard.php" method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= h($adminCsrf) ?>">
                    <div>
                        <label class="block text-xs font-bold text-stone-300 uppercase mb-1">Target Customer</label>
                        <select name="target_user_id" class="w-full bg-emerald-900 border border-emerald-800 rounded-xl p-2.5 text-xs text-white outline-none">
                            <option value="all">Broadcast to All Customers</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?= (int) $cust['id'] ?>"><?= h($cust['name']) ?> (#CUST-<?= (int) $cust['id'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-stone-300 uppercase mb-1">Notification Message</label>
                        <textarea name="notification_message" rows="3" required placeholder="Enter message here..." class="w-full bg-emerald-900 border border-emerald-800 rounded-xl p-2.5 text-xs text-white outline-none placeholder-stone-400"></textarea>
                    </div>
                    <button type="submit" name="send_admin_notification" value="1" class="w-full bg-amber-500 hover:bg-amber-400 text-emerald-950 font-extrabold py-3 rounded-xl transition text-xs border border-amber-600">Dispatch Notification</button>
                </form>
            </div>
        </div>

        <div id="notification-logs-section" class="dashboard-section section-panel p-4 sm:p-6" hidden>
            <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-stone-50 p-5">
                    <div>
                        <h2 class="text-base font-bold text-emerald-950">Notification Logs</h2>
                        <p class="mt-1 text-xs text-stone-500">Deleted notifications are archived and can be restored.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="admin_dashboard.php?section=logs" class="rounded-lg px-3 py-2 text-xs font-bold <?= !$showDeletedNotifications ? 'bg-emerald-800 text-white' : 'border border-stone-300 bg-white text-stone-700' ?>">Active notifications</a>
                        <a href="admin_dashboard.php?section=logs&amp;notifications=deleted" class="rounded-lg px-3 py-2 text-xs font-bold <?= $showDeletedNotifications ? 'bg-rose-700 text-white' : 'border border-stone-300 bg-white text-stone-700' ?>">Deleted archive</a>
                    </div>
                </div>
                <div class="space-y-3 p-4 sm:p-5">
                    <?php if (empty($allNotifications)): ?>
                        <p class="rounded-xl border border-stone-200 bg-stone-50 p-5 text-center text-sm text-stone-500">
                            <?= $showDeletedNotifications ? 'There are no archived notifications.' : 'No notifications to display.' ?>
                        </p>
                    <?php else: ?>
                        <?php foreach ($allNotifications as $n): ?>
                            <article class="flex flex-col gap-3 rounded-xl border border-stone-200 bg-white p-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-wider text-amber-700"><?= h($n['title']) ?></p>
                                    <p class="mt-1 break-words text-sm font-semibold text-stone-800"><?= h($n['message']) ?></p>
                                    <p class="mt-2 text-[11px] text-stone-500">To <?= h($n['name']) ?> (<?= h($n['email']) ?>) &middot; <?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></p>
                                </div>
                                <form action="admin_dashboard.php?section=logs<?= $showDeletedNotifications ? '&amp;notifications=deleted' : '' ?>" method="POST" class="shrink-0">
                                    <input type="hidden" name="csrf_token" value="<?= h($adminCsrf) ?>">
                                    <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                                    <button type="submit" name="notification_action" value="<?= $showDeletedNotifications ? 'restore' : 'delete' ?>"
                                            class="rounded-lg px-3 py-2 text-xs font-bold text-white <?= $showDeletedNotifications ? 'bg-emerald-800 hover:bg-emerald-700' : 'bg-rose-700 hover:bg-rose-600' ?>">
                                        <?= $showDeletedNotifications ? 'Restore' : 'Delete' ?>
                                    </button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="customer-directory-section" class="dashboard-section section-panel p-4 sm:p-6" hidden>
            <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
                <div class="p-5 bg-stone-50 border-b border-stone-200 flex justify-between items-center">
                    <div>
                        <h2 class="font-bold text-emerald-950 text-base">Registered Customer Directory</h2>
                        <p class="text-xs text-stone-500">View personal information and account details of registered customers.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="customer_history.php" class="text-xs font-bold text-emerald-800 hover:underline">Order History Report</a>
                        <a href="export_customers.php" class="text-xs text-amber-600 hover:underline font-bold">Download CSV</a>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-stone-100 uppercase text-stone-500 font-bold border-b">
                            <tr>
                                <th class="p-4">Customer ID</th>
                                <th class="p-4">Full Name</th>
                                <th class="p-4">Email Address</th>
                                <th class="p-4">Phone</th>
                                <th class="p-4">Address</th>
                                <th class="p-4">Account Role</th>
                                <th class="p-4">Registration Date</th>
                                <th class="p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            <?php if (empty($customers)): ?>
                                <tr><td colspan="8" class="p-4 text-center text-stone-500">No registered customers found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($customers as $c): ?>
                                    <tr class="hover:bg-stone-50">
                                        <td class="p-4 font-bold text-emerald-950">#CUST-<?= (int) $c['id'] ?></td>
                                        <td class="p-4 font-semibold text-stone-800"><?= h($c['name']) ?></td>
                                        <td class="p-4 text-stone-600"><?= h($c['email']) ?></td>
                                        <td class="p-4 text-stone-600"><?= h($c['contact_number'] ?? '') ?></td>
                                        <td class="p-4 text-stone-600"><?= h($c['address'] ?? '') ?></td>
                                        <td class="p-4"><span class="uppercase text-[10px] font-bold bg-stone-200 text-stone-800 px-2 py-0.5 rounded-full"><?= h($c['role']) ?></span></td>
                                        <td class="p-4 text-stone-500"><?= date('M d, Y h:i A', strtotime($c['created_at'])) ?></td>
                                        <td class="p-4">
                                            <a href="customer_detail.php?id=<?= (int) $c['id'] ?>" class="inline-flex items-center rounded-lg bg-emerald-800 px-3 py-2 text-[10px] font-bold text-white hover:bg-emerald-700">View Details</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="staff-section" class="dashboard-section section-panel p-4 sm:p-6" hidden>
            <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
                <div class="border-b border-stone-200 bg-stone-50 p-5">
                    <h2 class="text-base font-bold text-emerald-950">Staff Access</h2>
                    <p class="mt-1 text-xs text-stone-500">Review staff registrations and control workspace access.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-stone-100 text-xs uppercase text-stone-500">
                            <tr><th class="p-4">Staff</th><th class="p-4">Email</th><th class="p-4">Registered</th><th class="p-4">Status</th><th class="p-4 text-right">Action</th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            <?php if (!$staffAccounts): ?>
                                <tr><td colspan="5" class="p-8 text-center text-stone-500">No staff accounts have been registered.</td></tr>
                            <?php else: ?>
                                <?php foreach ($staffAccounts as $staff): ?>
                                    <tr class="hover:bg-stone-50">
                                        <td class="p-4 font-semibold text-emerald-950"><?= h($staff['name']) ?></td>
                                        <td class="p-4 text-stone-600"><?= h($staff['email']) ?></td>
                                        <td class="p-4 text-xs text-stone-500"><?= date('M j, Y', strtotime($staff['created_at'])) ?></td>
                                        <td class="p-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold <?= $staff['status'] === 'approved' ? 'bg-emerald-100 text-emerald-800' : ($staff['status'] === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-stone-200 text-stone-700') ?>"><?= h(ucfirst($staff['status'])) ?></span></td>
                                        <td class="p-4 text-right">
                                            <form method="POST" class="inline-flex">
                                                <input type="hidden" name="csrf_token" value="<?= h($adminCsrf) ?>">
                                                <input type="hidden" name="staff_id" value="<?= (int) $staff['id'] ?>">
                                                <?php if ($staff['status'] === 'approved'): ?>
                                                    <button type="submit" name="staff_access_action" value="disable" class="rounded-lg bg-rose-700 px-3 py-2 text-xs font-bold text-white hover:bg-rose-600">Disable</button>
                                                <?php else: ?>
                                                    <button type="submit" name="staff_access_action" value="approve" class="rounded-lg bg-emerald-800 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">Approve access</button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div id="modal-admin-profile" class="fixed inset-0 bg-stone-900/60 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-xl">
            <h3 class="text-lg font-bold text-emerald-950 border-b pb-2">Admin Profile Management</h3>
            <form action="admin_dashboard.php" method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="csrf_token" value="<?= h($adminCsrf) ?>">
                <div>
                    <label class="block font-bold text-stone-700 uppercase mb-1">Full Name</label>
                    <input type="text" name="admin_name" value="<?= h($_SESSION['name'] ?? 'Admin') ?>" required class="w-full bg-stone-50 border p-2.5 rounded-lg outline-none focus:border-emerald-800">
                </div>
                <div>
                    <label class="block font-bold text-stone-700 uppercase mb-1">Email Address</label>
                    <input type="email" name="admin_email" value="<?= h($_SESSION['email'] ?? 'admin@halalheritage.com') ?>" required class="w-full bg-stone-50 border p-2.5 rounded-lg outline-none focus:border-emerald-800">
                </div>
                <div>
                    <label class="block font-bold text-stone-700 uppercase mb-1">New Password (leave blank to keep current)</label>
                    <input type="password" name="admin_password" placeholder="At least 8 characters" minlength="8" class="w-full bg-stone-50 border p-2.5 rounded-lg outline-none focus:border-emerald-800">
                </div>
                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" onclick="closeModal('modal-admin-profile')" class="px-3 py-2 bg-stone-200 rounded-lg font-bold">Cancel</button>
                    <button type="submit" name="update_admin_profile" value="1" class="px-4 py-2 bg-emerald-800 text-white rounded-lg font-bold hover:bg-emerald-700 transition">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-receipt-picker" class="fixed inset-0 bg-stone-900/60 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-xl">
            <h3 class="text-lg font-bold text-emerald-950 border-b pb-2">System Receipt Terminal</h3>
            <div id="receipt-print-area" class="space-y-2 text-xs border p-4 rounded-xl bg-stone-50">
                <p class="text-center font-extrabold text-base text-emerald-950 uppercase">Halal Heritage Restaurant</p>
                <p class="text-center text-[10px] text-stone-500 border-b pb-2">Official Transaction Printable Receipt</p>
                <div class="flex justify-between font-bold pt-2">
                    <span id="receipt-ord-id">Order ID: #ORD-000</span>
                    <span><?= date('M d, Y') ?></span>
                </div>
                <div class="flex justify-between text-stone-700">
                    <span>Customer Name:</span>
                    <span id="receipt-cust-name" class="font-bold">Customer</span>
                </div>
                <div class="flex justify-between text-stone-700 font-extrabold text-sm border-t pt-2">
                    <span>Total Amount Paid:</span>
                    <span id="receipt-total" class="text-emerald-900">₱0.00</span>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal('modal-receipt-picker')" class="px-3 py-1.5 bg-stone-200 text-xs font-bold rounded-lg">Close</button>
                <button type="button" onclick="window.print()" class="px-4 py-1.5 bg-emerald-800 text-white text-xs font-bold rounded-lg">Print Receipt</button>
            </div>
        </div>
    </div>

    <script>
        function isAdminSidebarMobile() { return window.matchMedia('(max-width: 1023px)').matches; }
        function setMobileAdminSidebarOpen(isOpen) {
            const drawer = document.getElementById('admin-drawer');
            const overlay = document.getElementById('drawer-overlay');
            const toggle = document.getElementById('admin-sidebar-toggle');
            drawer.classList.toggle('is-open', isOpen);
            overlay.classList.toggle('is-visible', isOpen);
            toggle.classList.toggle('is-open', isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', isOpen ? 'Close admin navigation' : 'Open admin navigation');
        }
        function toggleDrawer() {
            if (!isAdminSidebarMobile()) return;
            const drawer = document.getElementById('admin-drawer');
            setMobileAdminSidebarOpen(!drawer.classList.contains('is-open'));
        }
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && document.getElementById('admin-drawer').classList.contains('is-open')) {
                setMobileAdminSidebarOpen(false);
                document.getElementById('admin-sidebar-toggle').focus();
            }
        });
        window.addEventListener('resize', () => {
            if (!isAdminSidebarMobile()) setMobileAdminSidebarOpen(false);
        });
        function openModal(id) {
            if (isAdminSidebarMobile()) setMobileAdminSidebarOpen(false);
            document.getElementById(id).classList.remove('hidden');
        }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function triggerReceiptPrint(ordId, custName, totalAmount) {
            document.getElementById('receipt-ord-id').textContent = '#ORD-' + String(ordId);
            document.getElementById('receipt-cust-name').textContent = custName;
            document.getElementById('receipt-total').textContent = '₱' + totalAmount;
            document.getElementById('modal-receipt-picker').classList.remove('hidden');
        }

        function showAdminSection(sectionId) {
            const sections = document.querySelectorAll('.dashboard-section');
            sections.forEach(section => { section.hidden = section.id !== sectionId; });
            const activeSection = {
                'orders-section': 'orders',
                'send-notification-section': 'notifications',
                'notification-logs-section': 'logs',
                'customer-directory-section': 'customers',
                'staff-section': 'staff',
            }[sectionId];
            document.querySelectorAll('.dashboard-overview').forEach(el => { el.hidden = activeSection !== 'orders'; });
            document.querySelectorAll('[data-message-section]').forEach(el => { el.hidden = el.dataset.messageSection !== activeSection; });
            document.querySelectorAll('[data-admin-section]').forEach(button => {
                const active = button.dataset.adminSection === activeSection;
                button.classList.toggle('is-active', active);
                button.classList.toggle('border-amber-500', active);
                button.classList.toggle('border-transparent', !active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            if (isAdminSidebarMobile()) setMobileAdminSidebarOpen(false);
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-admin-section]').forEach(button => {
                button.addEventListener('click', function () {
                    const map = {
                        orders: 'orders-section',
                        notifications: 'send-notification-section',
                        logs: 'notification-logs-section',
                        customers: 'customer-directory-section',
                        staff: 'staff-section',
                    };
                    const target = map[this.dataset.adminSection];
                    if (target) showAdminSection(target);
                });
            });
            const sectionMap = {
                orders: 'orders-section',
                notifications: 'send-notification-section',
                logs: 'notification-logs-section',
                customers: 'customer-directory-section',
                staff: 'staff-section',
            };
            showAdminSection(sectionMap[<?= json_encode($initialSection) ?>] || 'orders-section');
        });
    </script>
</body>
</html>