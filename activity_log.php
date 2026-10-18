<?php
require_once 'config.php';
checkAuth(['admin']);
ensureSystemActivityTable($pdo);

$search = trim((string) ($_GET['search'] ?? ''));
$params = [];
$query = 'SELECT created_at, actor_name, actor_role, action, details FROM system_activity_logs';
if ($search !== '') {
    $query .= ' WHERE actor_name LIKE ? OR actor_role LIKE ? OR action LIKE ? OR details LIKE ?';
    $term = '%' . $search . '%';
    $params = [$term, $term, $term, $term];
}
$query .= ' ORDER BY created_at DESC, id DESC LIMIT 500';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$activities = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Audit Trail - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-stone-100 text-stone-800">
    <?php include 'navbar.php'; ?>
    <main class="mx-auto max-w-7xl px-4 py-8">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Administration</p>
                <h1 class="mt-2 text-3xl font-extrabold text-emerald-950">System activity</h1>
            </div>
            <a href="admin_dashboard.php" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50">Back to dashboard</a>
        </div>

        <form method="GET" class="mb-5 flex flex-wrap gap-2">
            <label class="sr-only" for="activity-search">Search activity</label>
            <input id="activity-search" name="search" value="<?= h($search) ?>" type="search" placeholder="Search actor, action, or details" class="min-w-64 flex-1 rounded-lg border border-stone-300 bg-white px-4 py-2.5 text-sm focus:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">
            <button type="submit" class="rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700">Search</button>
            <?php if ($search !== ''): ?><a href="activity_log.php" class="rounded-lg border border-stone-300 bg-white px-4 py-2.5 text-sm font-semibold text-stone-700">Clear</a><?php endif; ?>
        </form>

        <div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-emerald-950 text-xs uppercase tracking-wide text-white">
                    <tr><th class="px-4 py-3">Time</th><th class="px-4 py-3">Actor</th><th class="px-4 py-3">Role</th><th class="px-4 py-3">Activity</th><th class="px-4 py-3">Details</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    <?php if (!$activities): ?>
                        <tr><td colspan="5" class="px-4 py-10 text-center text-stone-500">No activity records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <tr class="align-top hover:bg-stone-50">
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-stone-500"><?= date('M j, Y g:i:s A', strtotime($activity['created_at'])) ?></td>
                                <td class="px-4 py-3 font-semibold text-emerald-950"><?= h($activity['actor_name']) ?></td>
                                <td class="px-4 py-3 text-stone-600"><?= h(ucfirst($activity['actor_role'])) ?></td>
                                <td class="px-4 py-3 font-semibold"><?= h($activity['action']) ?></td>
                                <td class="max-w-md px-4 py-3 text-stone-600"><?= h($activity['details'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-stone-500">Showing up to 500 most recent records.</p>
    </main>
</body>
</html>