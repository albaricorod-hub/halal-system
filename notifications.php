<?php
require_once 'config.php';
checkAuth();
ensureNotificationArchiveColumn($pdo);

$userId       = (int) $_SESSION['user_id'];
$notifCsrf    = csrfToken('notification_csrf');
$notice       = '';
$error        = '';

/* ------------------------------------------------------------------ */
/* User archive / restore their own notifications                     */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notif_action'])) {
    requireCsrf('notification_csrf');

    $notifId = filter_var($_POST['notification_id'] ?? 0, FILTER_VALIDATE_INT);
    $action  = (string) ($_POST['notif_action'] ?? '');

    if (!$notifId || !in_array($action, ['archive', 'restore'], true)) {
        $error = 'Invalid notification action.';
    } else {
        $isDeleted = $action === 'archive' ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE notifications SET is_deleted = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$isDeleted, $notifId, $userId]);
        if ($stmt->rowCount() > 0) {
            $notice = $isDeleted ? 'Notification archived.' : 'Notification restored.';
        } else {
            $error = 'Notification not found or already in that state.';
        }
    }
}

/* ------------------------------------------------------------------ */
/* Mark visible notifications as read on view                          */
/* ------------------------------------------------------------------ */
$showArchived = ($_GET['view'] ?? '') === 'archived';
$filterDeleted = $showArchived ? 1 : 0;

if (!$showArchived) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_deleted = 0 AND is_read = 0')
        ->execute([$userId]);
}

$stmt = $pdo->prepare(
    'SELECT id, title, message, is_read, is_deleted, created_at
     FROM notifications
     WHERE user_id = ? AND is_deleted = ?
     ORDER BY created_at DESC, id DESC
     LIMIT 200'
);
$stmt->execute([$userId, $filterDeleted]);
$notifications = $stmt->fetchAll();

$countStmt = $pdo->prepare('SELECT
    SUM(CASE WHEN is_deleted = 0 THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) AS archived_count
    FROM notifications WHERE user_id = ?');
$countStmt->execute([$userId]);
$counts = $countStmt->fetch() ?: ['active_count' => 0, 'archived_count' => 0];
$activeCount   = (int) ($counts['active_count'] ?? 0);
$archivedCount = (int) ($counts['archived_count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-100 text-stone-800 min-h-screen flex flex-col font-sans">
    <?php include 'navbar.php'; ?>

    <main class="max-w-4xl mx-auto px-4 py-8 flex-grow w-full">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3 border-b border-stone-300 pb-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-emerald-950 flex items-center gap-2">
                    <svg class="w-7 h-7 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 01-6 0v-1m6 0H9"></path>
                    </svg>
                    <span>Your Notifications</span>
                </h1>
                <p class="text-stone-600 text-sm mt-1">Order updates, kitchen messages, and system alerts for your account.</p>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900"><?= h($notice) ?></div>
        <?php endif; ?>

        <div class="flex gap-2 mb-5">
            <a href="notifications.php" class="px-4 py-2 rounded-lg text-sm font-semibold <?= !$showArchived ? 'bg-emerald-800 text-white' : 'bg-white text-stone-700 border border-stone-300' ?>">
                Inbox (<?= $activeCount ?>)
            </a>
            <a href="notifications.php?view=archived" class="px-4 py-2 rounded-lg text-sm font-semibold <?= $showArchived ? 'bg-emerald-800 text-white' : 'bg-white text-stone-700 border border-stone-300' ?>">
                Archived (<?= $archivedCount ?>)
            </a>
        </div>

        <div class="space-y-4">
            <?php if (empty($notifications)): ?>
                <div class="bg-white p-8 rounded-2xl text-center text-stone-500 border border-stone-200">
                    <?= $showArchived ? 'No archived notifications.' : 'No notifications yet.' ?>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notif): ?>
                    <article class="bg-white p-4 rounded-xl border-l-4 <?= $showArchived ? 'border-stone-400' : 'border-amber-500' ?> shadow-sm flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-wider font-bold text-amber-700 mb-1"><?= h($notif['title']) ?></p>
                            <p class="text-sm font-semibold text-stone-800 break-words"><?= h($notif['message']) ?></p>
                            <p class="text-[11px] text-stone-500 mt-2"><?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></p>
                        </div>
                        <form method="POST" class="shrink-0">
                            <input type="hidden" name="csrf_token" value="<?= h($notifCsrf) ?>">
                            <input type="hidden" name="notification_id" value="<?= (int) $notif['id'] ?>">
                            <?php if ($showArchived): ?>
                                <button type="submit" name="notif_action" value="restore"
                                        class="rounded-lg bg-emerald-800 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">
                                    Restore
                                </button>
                            <?php else: ?>
                                <button type="submit" name="notif_action" value="archive"
                                        class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-bold text-stone-700 hover:bg-stone-50">
                                    Archive
                                </button>
                            <?php endif; ?>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <p class="mt-6 text-xs text-stone-500">Showing up to 200 notifications. Archived items stay visible here and can be restored at any time.</p>
    </main>
</body>
</html>