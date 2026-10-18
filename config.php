<?php
/**
 * Halal Heritage — central configuration and shared helpers.
 * CRITICAL: Update the database credentials below to match your InfinityFree account!
 */

$host   = 'sqlXXX.infinityfree.com'; // Change this
$user   = 'if0_XXXXXXX';             // Change this
$pass   = 'YourPasswordHere';        // Change this
$dbname = 'if0_XXXXXXX_halal';       // Change this

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('Database Connection Failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed. Please check config.php credentials.');
}

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
}

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsJson($value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function csrfToken(string $key = 'csrf'): string {
    if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}

function csrfField(string $key = 'csrf'): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken($key)) . '">';
}

function renderForbidden(string $message = 'Access Denied'): void {
    http_response_code(403);
    exit("<div style='padding:50px;text-align:center;font-family:sans-serif;'><h1>403 Forbidden</h1><p>" . h($message) . "</p><a href='index.php'>Return Home</a></div>");
}

function requireCsrf(string $key = 'csrf'): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $posted = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION[$key] ?? '';
    if (!is_string($posted) || !is_string($stored) || $stored === '' || !hash_equals($stored, $posted)) {
        renderForbidden('Session expired or invalid token.');
    }
}

function forceLogout(string $reason = ''): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php' . ($reason ? '?reason=' . urlencode($reason) : ''));
    exit;
}

function checkAuth(array $allowedRoles = []): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php'); exit;
    }
    global $pdo;
    $stmt = $pdo->prepare('SELECT id, name, role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $account = $stmt->fetch();

    if (!$account) forceLogout('invalid_session');

    $dbRole = strtolower(trim((string) ($account['role'] ?? '')));
    $dbStatus = strtolower(trim((string) ($account['status'] ?? '')));

    if ($dbRole === 'staff' && $dbStatus !== 'approved') forceLogout('staff_not_approved');
    if (in_array($dbStatus, ['inactive', 'disabled', 'banned', 'suspended'], true)) forceLogout('account_disabled');

    $_SESSION['role'] = $dbRole;
    $_SESSION['name'] = $account['name'];

    if (!empty($allowedRoles)) {
        $normalized = array_map(static fn($r) => strtolower(trim((string) $r)), $allowedRoles);
        if (!in_array($dbRole, $normalized, true)) renderForbidden('You do not have permission to view this page.');
    }
}

function createNotification(PDO $pdo, int $userId, string $title, string $message): void {
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
    $stmt->execute([$userId, mb_substr($title, 0, 150), $message]);
}

function ensureSystemActivityTable(PDO $pdo): void {
    static $ready = false; if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_activity_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, actor_id INT NULL, actor_name VARCHAR(100) NOT NULL, actor_role VARCHAR(30) NOT NULL, action VARCHAR(100) NOT NULL, details TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function recordSystemActivity(PDO $pdo, string $action, string $details = '', ?int $actorId = null, ?string $actorName = null, ?string $actorRole = null): void {
    ensureSystemActivityTable($pdo);
    $actorId ??= isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $actorName = mb_substr($actorName ?? (string) ($_SESSION['name'] ?? 'Guest'), 0, 100);
    $actorRole = mb_substr($actorRole ?? (string) ($_SESSION['role'] ?? 'guest'), 0, 30);
    $stmt = $pdo->prepare('INSERT INTO system_activity_logs (actor_id, actor_name, actor_role, action, details) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$actorId, $actorName, $actorRole, mb_substr($action, 0, 100), $details]);
}

function notifyAdmins(PDO $pdo, string $message): void {
    $adminIds = $pdo->query("SELECT id FROM users WHERE LOWER(role) = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($adminIds as $adminId) createNotification($pdo, (int) $adminId, 'System update', $message);
}

function ensureNotificationArchiveColumn(PDO $pdo): void {
    static $ready = false; if ($ready) return;
    $col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_deleted'")->fetch();
    if (!$col) $pdo->exec("ALTER TABLE notifications ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0");
    $ready = true;
}

function halalSupplierCertificationSelect(PDO $pdo): string {
    static $select = null;
    if ($select === null) {
        $column = $pdo->query("SHOW COLUMNS FROM halal_suppliers LIKE 'certification_no'")->fetch();
        $select = $column ? 's.certification_no' : 'NULL AS certification_no';
    }
    return $select;
}

function ensureHalalSupplierDirectoryColumns(PDO $pdo): void {
    static $ready = false; if ($ready) return;
    $columns = [
        'certification_no' => "VARCHAR(100) NULL", 'supplier_type' => "VARCHAR(32) NULL", 'address' => "VARCHAR(255) NULL",
        'city' => "VARCHAR(100) NULL", 'region' => "VARCHAR(100) NULL", 'postal_code' => "VARCHAR(20) NULL",
        'phone' => "VARCHAR(40) NULL", 'website' => "VARCHAR(255) NULL", 'latitude' => "DECIMAL(10,7) NULL",
        'longitude' => "DECIMAL(10,7) NULL", 'certification_body' => "VARCHAR(150) NULL", 'certification_valid_until' => "DATE NULL",
        'supplier_user_id' => "INT NULL", 'verification_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'verified_at' => "DATETIME NULL", 'verified_by' => "INT NULL",
    ];
    foreach ($columns as $name => $definition) {
        $column = $pdo->query("SHOW COLUMNS FROM halal_suppliers LIKE " . $pdo->quote($name))->fetch();
        if (!$column) $pdo->exec("ALTER TABLE halal_suppliers ADD COLUMN `$name` $definition");
    }
    $ready = true;
}

function halalMeatCutOptions(): array {
    return ['none' => 'No special cut', 'bone_in' => 'Bone-in', 'diced' => 'Diced', 'heritage' => 'Traditional heritage cut'];
}

function foodSupportsCustomMeatCuts(array $food): bool {
    $description = strtolower((string) ($food['name'] ?? '') . ' ' . (string) ($food['ingredients'] ?? ''));
    return preg_match('/\b(beef|chicken|lamb|goat|mutton|poultry|meat)\b/', $description) === 1;
}

function halalDishCustomizationOptions(array $food): array {
    $name = strtolower((string) ($food['name'] ?? ''));
    $description = $name . ' ' . strtolower((string) ($food['ingredients'] ?? ''));
    if (strpos($name, 'halo-halo') !== false) return ['sweet_beans' => 'Sweet beans', 'coconut_gel' => 'Coconut gel', 'evaporated_milk' => 'Evaporated milk', 'crushed_ice' => 'Crushed ice'];
    if (strpos($name, 'mango sago') !== false) return ['extra_mango' => 'Extra mango', 'coconut_sago' => 'Coconut sago', 'sweet_cream' => 'Sweet cream', 'crushed_ice' => 'Crushed ice'];
    if (strpos($name, 'pandan') !== false && strpos($name, 'pudding') !== false) return ['coconut_cream' => 'Coconut cream', 'toasted_coconut' => 'Toasted coconut', 'pandan' => 'Extra pandan'];
    if (preg_match('/\b(soup|laksa|mami|broth)\b/', $description)) return ['extra_broth' => 'Extra broth', 'less_spicy' => 'Less spicy', 'extra_spicy' => 'Extra spicy', 'extra_herbs' => 'Extra herbs'];
    if (preg_match('/\b(rice|biryani|kabsa|pastil)\b/', $description)) return ['extra_rice' => 'Extra rice', 'less_spicy' => 'Less spicy', 'extra_spicy' => 'Extra spicy', 'no_garnish' => 'No garnish'];
    if (preg_match('/\b(grill|grilled|satay|satti|skewer|barbecue|inasal|tandoori)\b/', $description)) return ['extra_sauce' => 'Extra sauce', 'less_spicy' => 'Less spicy', 'extra_spicy' => 'Extra spicy', 'no_garnish' => 'No garnish'];
    return ['less_spicy' => 'Less spicy', 'extra_spicy' => 'Extra spicy', 'extra_sauce' => 'Extra sauce', 'no_garnish' => 'No garnish'];
}

function ensureOrderItemCustomizationColumns(PDO $pdo): void {
    static $ready = false; if ($ready) return;
    $columns = ['custom_cut' => "VARCHAR(32) NOT NULL DEFAULT 'none'", 'custom_toppings' => "VARCHAR(500) NOT NULL DEFAULT '[]'"];
    foreach ($columns as $name => $definition) {
        $column = $pdo->query("SHOW COLUMNS FROM order_items LIKE " . $pdo->quote($name))->fetch();
        if (!$column) $pdo->exec("ALTER TABLE order_items ADD COLUMN `$name` $definition");
    }
    $ready = true;
}

function ensurePaymentProofColumn(PDO $pdo): void {
    static $ready = false; if ($ready) return;
    $column = $pdo->query("SHOW COLUMNS FROM payments LIKE 'proof_path'")->fetch();
    if (!$column) $pdo->exec("ALTER TABLE payments ADD COLUMN proof_path VARCHAR(255) NULL");
    $ready = true;
}

function storeGcashPaymentProof(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Upload a screenshot of your completed GCash payment.');
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('The GCash screenshot must be 5 MB or smaller.');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) throw new RuntimeException('Unable to verify the uploaded screenshot.');
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mimeType])) throw new RuntimeException('Upload the GCash screenshot as a JPG, PNG, or WEBP image.');
    $uploadDirectory = __DIR__ . '/uploads/payment_proofs';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) throw new RuntimeException('Unable to save the GCash screenshot.');
    $fileName = 'payment_' . bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . '/' . $fileName)) throw new RuntimeException('Unable to save the GCash screenshot.');
    return 'uploads/payment_proofs/' . $fileName;
}

function ensureCustomerLoyaltyColumns(PDO $pdo): void {
    static $ready = false; if ($ready) return;
    $columns = ['loyalty_points' => "INT NOT NULL DEFAULT 0", 'loyalty_level' => "VARCHAR(30) NOT NULL DEFAULT 'Bronze'", 'total_spent' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00"];
    foreach ($columns as $name => $definition) {
        $column = $pdo->query("SHOW COLUMNS FROM users LIKE " . $pdo->quote($name))->fetch();
        if (!$column) $pdo->exec("ALTER TABLE users ADD COLUMN `$name` $definition");
    }
    $ready = true;
}

function getCustomerLoyaltyStatus(int $points): array {
    $tiers = ['Bronze' => 0, 'Silver' => 500, 'Gold' => 1500, 'Platinum' => 3500];
    $level = 'Bronze'; $nextLevel = null; $nextThreshold = null; $currentThreshold = 0;
    foreach ($tiers as $tierName => $threshold) {
        if ($points >= $threshold) { $level = $tierName; $currentThreshold = $threshold; } elseif ($nextLevel === null) { $nextLevel = $tierName; $nextThreshold = $threshold; }
    }
    if ($nextThreshold === null) return ['level' => $level, 'points' => $points, 'total_spent' => 0.0, 'next_level' => null, 'next_threshold' => null, 'points_needed' => 0, 'progress' => 100];
    $pointsNeeded = max(0, $nextThreshold - $points); $requiredRange = max(1, $nextThreshold - $currentThreshold); $progress = (int) min(100, max(0, (($points - $currentThreshold) / $requiredRange) * 100));
    return ['level' => $level, 'points' => $points, 'total_spent' => 0.0, 'next_level' => $nextLevel, 'next_threshold' => $nextThreshold, 'points_needed' => $pointsNeeded, 'progress' => $progress];
}

function customerLoyaltyTierRewards(): array {
    return ['Silver' => ['threshold' => 500, 'food_name' => 'Halo-Halo Special (Halal Certified)'], 'Gold' => ['threshold' => 1500, 'food_name' => 'Crispy Chicken Pastil'], 'Platinum' => ['threshold' => 3500, 'food_name' => 'Chicken Piaparan Maranao']];
}

function ensureCustomerLoyaltyRewardsTable(PDO $pdo): void {
    static $ready = false; if ($ready) return;
    $existing = $pdo->query("SHOW TABLES LIKE 'customer_loyalty_rewards'")->fetchColumn();
    if ($existing) { $ready = true; return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_loyalty_rewards (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, tier VARCHAR(30) NOT NULL, food_name VARCHAR(150) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', order_id INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_customer_loyalty_reward (user_id, tier), KEY idx_customer_loyalty_reward_pending (user_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function refreshCustomerLoyalty(PDO $pdo, int $userId): array {
    ensureCustomerLoyaltyColumns($pdo); ensureCustomerLoyaltyRewardsTable($pdo);
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND LOWER(role) = 'customer' LIMIT 1"); $check->execute([$userId]);
    if (!$check->fetchColumn()) throw new RuntimeException('Loyalty can only be refreshed for a customer account.');
    $totalStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = ? AND (LOWER(order_status) = 'completed' OR LOWER(status) = 'completed')"); $totalStmt->execute([$userId]);
    $totalSpent = (float) $totalStmt->fetchColumn(); $points = (int) floor($totalSpent / 100); $status = getCustomerLoyaltyStatus($points); $status['total_spent'] = $totalSpent;
    $upd = $pdo->prepare("UPDATE users SET total_spent = ?, loyalty_points = ?, loyalty_level = ? WHERE id = ? AND LOWER(role) = 'customer'"); $upd->execute([$totalSpent, $points, $status['level'], $userId]);
    $earned = []; $insert = $pdo->prepare('INSERT IGNORE INTO customer_loyalty_rewards (user_id, tier, food_name) VALUES (?, ?, ?)'); $revoke = $pdo->prepare("DELETE FROM customer_loyalty_rewards WHERE user_id = ? AND tier = ? AND status = 'pending'");
    foreach (customerLoyaltyTierRewards() as $tier => $reward) {
        if ($points >= $reward['threshold']) { $insert->execute([$userId, $tier, $reward['food_name']]); if ($insert->rowCount() > 0) $earned[] = $reward; } else { $revoke->execute([$userId, $tier]); }
    }
    $status['earned_rewards'] = $earned; return $status;
}

function ensureUserProfileImageColumn(PDO $pdo): void {
    static $ready = false; if ($ready) return;
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'")->fetch();
    if (!$column) $pdo->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL AFTER email");
    $ready = true;
}

function ensureDefaultFoods(PDO $pdo): void {
    static $ran = false; if ($ran) return; $ran = true;
    $pdo->exec("INSERT IGNORE INTO food_categories (id, name) VALUES (1, 'Traditional Heritage'), (2, 'Halal Barbecue & Grills'), (3, 'Rice & Noodles'), (4, 'Desserts & Beverages')");
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
    $check = $pdo->prepare('SELECT id FROM foods WHERE name = ? LIMIT 1'); $insert = $pdo->prepare("INSERT INTO foods (name, category_id, price, ingredients, is_heritage, status) VALUES (?, ?, ?, ?, ?, 'approved')");
    foreach ($defaultFoods as $row) { $check->execute([$row[0]]); if (!$check->fetch()) $insert->execute($row); }
}

function getHalalTraceabilityDetails(array $food): array {
    $halalInfo = trim((string) ($food['halal_info'] ?? '')); $slaughterMethod = trim((string) ($food['slaughter_method'] ?? ''));
    if ($slaughterMethod === '' && preg_match('/\b(hand[\s-]+slaughtered|machine[\s-]+slaughtered|slaughtered by hand)\b/i', $halalInfo, $m)) $slaughterMethod = $m[1];
    return ['origin' => trim((string) ($food['origin'] ?? '')) ?: 'Not provided', 'slaughter_method' => $slaughterMethod ?: 'Not specified by supplier', 'certification_no' => trim((string) ($food['certification_no'] ?? ''))];
}

function renderHalalTraceabilityTag(array $food): void {
    $d = getHalalTraceabilityDetails($food); $cert = h($d['certification_no']);
    ?>
    <section class="rounded-xl border border-emerald-100 bg-emerald-50/70 p-3.5 text-xs" aria-label="Halal certification and source details">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <?php if ($d['certification_no'] !== ''): ?>
                <span class="inline-flex items-center gap-1 rounded-full border border-emerald-300 bg-white px-2.5 py-1 font-extrabold text-emerald-900"><span aria-hidden="true">&#10003;</span> Halal certification ID on file</span>
            <?php else: ?>
                <span class="inline-flex items-center rounded-full border border-stone-300 bg-white px-2.5 py-1 font-bold text-stone-600">No certificate ID listed</span>
            <?php endif; ?>
        </div>
        <?php if ($d['certification_no'] !== ''): ?>
            <p class="mb-2 text-stone-600">Supplier certificate ID: <span class="font-bold text-emerald-950"><?= $cert ?></span></p>
        <?php endif; ?>
        <dl class="grid gap-1.5 text-stone-600 sm:grid-cols-2">
            <div><dt class="inline font-bold text-emerald-950">Farm / source origin:</dt> <dd class="inline"><?= h($d['origin']) ?></dd></div>
            <div><dt class="inline font-bold text-emerald-950">Slaughter method:</dt> <dd class="inline"><?= h($d['slaughter_method']) ?></dd></div>
        </dl>
        <p class="mt-2 text-[10px] leading-relaxed text-stone-500">Details are supplier-provided. Confirm certificate validity with its issuing body.</p>
    </section>
    <?php
}