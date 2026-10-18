<?php
require_once 'config.php';
checkAuth();
ensureHalalSupplierDirectoryColumns($pdo);

$role               = strtolower(trim((string) ($_SESSION['role'] ?? 'guest')));
$canManageDirectory = in_array($role, ['admin', 'staff'], true);
$supplierCsrf       = csrfToken('supplier_directory_csrf');

$error   = '';
$success = '';

$supplierTypes = [
    'farm'        => 'Farm',
    'butcher'     => 'Butcher shop',
    'meat_market' => 'Meat market',
    'processor'   => 'Meat processor',
    'abattoir'    => 'Abattoir',
];

$postString = static fn(string $key): string => isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';

$geocodeSupplierAddress = static function (PDO $pdo, string $address): ?array {
    $address = trim($address);
    if ($address === '') {
        return null;
    }
    $lock = $pdo->query("SELECT GET_LOCK('halal_heritage_supplier_geocode', 10)")->fetchColumn();
    if ((int) $lock !== 1) {
        return null;
    }
    try {
        usleep(1100000);
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query(['format' => 'jsonv2', 'limit' => 1, 'q' => $address]);
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'HalalHeritageSupplierDirectory/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($response) || $httpStatus !== 200) {
            return null;
        }
        $results = json_decode($response, true);
        $lat = $results[0]['lat'] ?? null;
        $lon = $results[0]['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)
            || (float) $lat < -90 || (float) $lat > 90
            || (float) $lon < -180 || (float) $lon > 180) {
            return null;
        }
        return ['latitude' => (float) $lat, 'longitude' => (float) $lon];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('halal_heritage_supplier_geocode')");
    }
};

$geocodeSupplierRecord = static function (PDO $pdo, array $supplier) use ($geocodeSupplierAddress): ?array {
    $street = trim((string) ($supplier['address'] ?? ''));
    if ($street === '') {
        return null;
    }
    return $geocodeSupplierAddress($pdo, implode(', ', array_filter([
        $street,
        trim((string) ($supplier['city'] ?? '')),
        trim((string) ($supplier['region'] ?? '')),
        trim((string) ($supplier['postal_code'] ?? '')),
    ])));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageDirectory) {
        renderForbidden('Only staff and administrators can manage supplier directory listings.');
    }
    requireCsrf('supplier_directory_csrf');

    $action = $postString('action');

    if ($action === 'save_supplier_profile') {
        $profileTargetId = (int) filter_var($postString('target_supplier_id'), FILTER_VALIDATE_INT);
        if ($profileTargetId > 0) {
            $exists = $pdo->prepare('SELECT id FROM halal_suppliers WHERE id = ? LIMIT 1');
            $exists->execute([$profileTargetId]);
            if (!$exists->fetchColumn()) {
                $profileTargetId = 0;
            }
        }
        $type         = $postString('supplier_type');
        $address      = $postString('address');
        $city         = $postString('city');
        $region       = $postString('region');
        $postalCode   = $postString('postal_code');
        $phone        = $postString('phone');
        $website      = $postString('website');
        $latitudeIn   = $postString('latitude');
        $longitudeIn  = $postString('longitude');

        if ($profileTargetId < 1) {
            $error = 'Choose an existing supplier profile.';
        } elseif (!array_key_exists($type, $supplierTypes) || $city === '' || $address === '') {
            $error = 'Choose a supplier type and enter a street address and city.';
        } elseif (($latitudeIn === '') !== ($longitudeIn === '')) {
            $error = 'Enter both map coordinates, or leave both blank.';
        } elseif ($latitudeIn !== '' && (!is_numeric($latitudeIn) || !is_numeric($longitudeIn)
            || (float) $latitudeIn < -90 || (float) $latitudeIn > 90
            || (float) $longitudeIn < -180 || (float) $longitudeIn > 180)) {
            $error = 'Enter valid latitude and longitude coordinates.';
        } elseif ($website !== '' && (!filter_var($website, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($website, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $error = 'Enter a complete website URL, including https://.';
        } elseif (mb_strlen($address) > 255 || mb_strlen($city) > 100 || mb_strlen($region) > 100
            || mb_strlen($postalCode) > 20 || mb_strlen($phone) > 40 || mb_strlen($website) > 255) {
            $error = 'One or more profile fields exceed the allowed length.';
        } else {
            $pdo->prepare(
                "UPDATE halal_suppliers
                 SET supplier_type = ?, address = ?, city = ?, region = ?, postal_code = ?, phone = ?, website = ?,
                     latitude = ?, longitude = ?, status = 'pending', verification_status = 'pending', verified_at = NULL, verified_by = NULL
                 WHERE id = ?"
            )->execute([
                $type, $address, $city, $region ?: null, $postalCode ?: null, $phone ?: null, $website ?: null,
                $latitudeIn !== '' ? (float) $latitudeIn : null,
                $longitudeIn !== '' ? (float) $longitudeIn : null,
                $profileTargetId,
            ]);
            header('Location: halal_suppliers.php?profile_saved=1');
            exit;
        }
    } elseif ($action === 'verify_supplier') {
        $targetId = filter_var($postString('supplier_id'), FILTER_VALIDATE_INT);
        if (!$targetId || $targetId < 1) {
            $error = 'Choose a valid supplier.';
        } else {
            $candidate = $pdo->prepare('SELECT id, address, city, region, postal_code, latitude, longitude FROM halal_suppliers WHERE id = ? LIMIT 1');
            $candidate->execute([$targetId]);
            $supplier = $candidate->fetch();
            if (!$supplier) {
                $error = 'Choose an existing supplier profile.';
            } else {
                $hasCoords = is_numeric($supplier['latitude'] ?? null) && is_numeric($supplier['longitude'] ?? null)
                    && (float) $supplier['latitude'] >= -90 && (float) $supplier['latitude'] <= 90
                    && (float) $supplier['longitude'] >= -180 && (float) $supplier['longitude'] <= 180;
                $coordinates = $hasCoords ? null : $geocodeSupplierRecord($pdo, $supplier);
                $pdo->prepare(
                    "UPDATE halal_suppliers
                     SET status = 'verified', verification_status = 'verified', verified_at = NOW(), verified_by = ?,
                         latitude = COALESCE(?, latitude), longitude = COALESCE(?, longitude)
                     WHERE id = ?"
                )->execute([(int) $_SESSION['user_id'], $coordinates['latitude'] ?? null, $coordinates['longitude'] ?? null, $targetId]);
                $mapStatus = $hasCoords ? 'existing' : ($coordinates ? 'added' : 'unavailable');
                header('Location: halal_suppliers.php?reviewed=1&map_location=' . $mapStatus);
                exit;
            }
        }
    } elseif ($action === 'locate_supplier') {
        $targetId = filter_var($postString('supplier_id'), FILTER_VALIDATE_INT);
        if (!$targetId || $targetId < 1) {
            $error = 'Choose a valid supplier.';
        } else {
            $candidate = $pdo->prepare("SELECT id, address, city, region, postal_code, latitude, longitude FROM halal_suppliers WHERE id = ? AND verification_status = 'verified' LIMIT 1");
            $candidate->execute([$targetId]);
            $supplier = $candidate->fetch();
            if (!$supplier) {
                $error = 'Choose an existing verified supplier.';
            } else {
                $hasCoords = is_numeric($supplier['latitude'] ?? null) && is_numeric($supplier['longitude'] ?? null)
                    && (float) $supplier['latitude'] >= -90 && (float) $supplier['latitude'] <= 90
                    && (float) $supplier['longitude'] >= -180 && (float) $supplier['longitude'] <= 180;
                $coordinates = $hasCoords ? null : $geocodeSupplierRecord($pdo, $supplier);
                if ($hasCoords) {
                    header('Location: halal_suppliers.php?reviewed=1&map_location=existing');
                } elseif ($coordinates) {
                    $pdo->prepare('UPDATE halal_suppliers SET latitude = ?, longitude = ? WHERE id = ?')
                        ->execute([$coordinates['latitude'], $coordinates['longitude'], $targetId]);
                    header('Location: halal_suppliers.php?reviewed=1&map_location=added');
                } else {
                    header('Location: halal_suppliers.php?reviewed=1&map_location=unavailable');
                }
                exit;
            }
        }
    } elseif ($action === 'revoke_supplier') {
        $targetId = filter_var($postString('supplier_id'), FILTER_VALIDATE_INT);
        if (!$targetId) {
            $error = 'Choose a valid supplier.';
        } else {
            $pdo->prepare("UPDATE halal_suppliers SET status = 'pending', verification_status = 'pending', verified_at = NULL, verified_by = NULL WHERE id = ?")
                ->execute([$targetId]);
            header('Location: halal_suppliers.php?reviewed=1');
            exit;
        }
    } elseif ($action === 'create_supplier_profile') {
        $supplierName = $postString('supplier_name');
        $type         = $postString('supplier_type');
        $address      = $postString('address');
        $city         = $postString('city');
        $region       = $postString('region');
        $postalCode   = $postString('postal_code');
        $phone        = $postString('phone');
        $website      = $postString('website');
        $latitudeIn   = $postString('latitude');
        $longitudeIn  = $postString('longitude');
        $accountInput = $postString('supplier_user_id');
        $accountId    = $accountInput !== '' ? filter_var($accountInput, FILTER_VALIDATE_INT) : null;

        if ($supplierName === '' || mb_strlen($supplierName) > 100) {
            $error = 'Enter a supplier name of up to 100 characters.';
        } elseif (!array_key_exists($type, $supplierTypes) || $city === '' || $address === '') {
            $error = 'Choose a supplier type and enter a street address and city.';
        } elseif (($latitudeIn === '') !== ($longitudeIn === '')) {
            $error = 'Enter both map coordinates, or leave both blank.';
        } elseif ($latitudeIn !== '' && (!is_numeric($latitudeIn) || !is_numeric($longitudeIn)
            || (float) $latitudeIn < -90 || (float) $latitudeIn > 90
            || (float) $longitudeIn < -180 || (float) $longitudeIn > 180)) {
            $error = 'Enter valid latitude and longitude coordinates.';
        } elseif ($website !== '' && (!filter_var($website, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($website, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $error = 'Enter a complete website URL, including https://.';
        } elseif ($accountInput !== '' && (!$accountId || $accountId < 1)) {
            $error = 'Choose a valid supplier account.';
        } elseif (mb_strlen($address) > 255 || mb_strlen($city) > 100 || mb_strlen($region) > 100
            || mb_strlen($postalCode) > 20 || mb_strlen($phone) > 40 || mb_strlen($website) > 255) {
            $error = 'One or more profile fields exceed the allowed length.';
        } else {
            $linkedAccountId = null;
            if ($accountId) {
                $aq = $pdo->prepare("SELECT id FROM users WHERE id = ? AND LOWER(role) = 'supplier' LIMIT 1");
                $aq->execute([$accountId]);
                $already = $pdo->prepare('SELECT id FROM halal_suppliers WHERE supplier_user_id = ? LIMIT 1');
                $already->execute([$accountId]);
                if (!$aq->fetchColumn()) {
                    $error = 'Choose an existing supplier account (role must be "supplier").';
                } elseif ($already->fetchColumn()) {
                    $error = 'That supplier account is already linked to a supplier profile.';
                } else {
                    $linkedAccountId = $accountId;
                }
            }
            if ($error === '') {
                $pdo->prepare(
                    "INSERT INTO halal_suppliers (
                        supplier_name, supplier_type, address, city, region, postal_code, phone, website,
                        latitude, longitude, supplier_user_id, status, verification_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')"
                )->execute([
                    $supplierName, $type, $address, $city, $region ?: null, $postalCode ?: null,
                    $phone ?: null, $website ?: null,
                    $latitudeIn !== '' ? (float) $latitudeIn : null,
                    $longitudeIn !== '' ? (float) $longitudeIn : null,
                    $linkedAccountId,
                ]);
                header('Location: halal_suppliers.php?edit_supplier=' . (int) $pdo->lastInsertId() . '#supplier-profile');
                exit;
            }
        }
    } elseif ($action === 'link_supplier_account') {
        $targetId = filter_var($postString('supplier_id'), FILTER_VALIDATE_INT);
        $accountInput = $postString('supplier_user_id');
        $accountId = $accountInput !== '' ? filter_var($accountInput, FILTER_VALIDATE_INT) : null;
        if (!$targetId) {
            $error = 'Choose a valid supplier.';
        } elseif ($accountInput !== '' && (!$accountId || $accountId < 1)) {
            $error = 'Choose a valid supplier account.';
        } else {
            $exists = $pdo->prepare('SELECT id FROM halal_suppliers WHERE id = ? LIMIT 1');
            $exists->execute([$targetId]);
            if (!$exists->fetchColumn()) {
                $error = 'Choose an existing supplier profile.';
            } elseif ($accountId) {
                $aq = $pdo->prepare("SELECT id FROM users WHERE id = ? AND LOWER(role) = 'supplier' LIMIT 1");
                $aq->execute([$accountId]);
                $already = $pdo->prepare('SELECT id FROM halal_suppliers WHERE supplier_user_id = ? AND id <> ? LIMIT 1');
                $already->execute([$accountId, $targetId]);
                if (!$aq->fetchColumn()) {
                    $error = 'Choose an existing supplier account (role must be "supplier").';
                } elseif ($already->fetchColumn()) {
                    $error = 'That supplier account is already linked to another supplier profile.';
                }
            }
            if ($error === '') {
                $pdo->prepare('UPDATE halal_suppliers SET supplier_user_id = ? WHERE id = ?')
                    ->execute([$accountId ?: null, $targetId]);
                header('Location: halal_suppliers.php?linked=1');
                exit;
            }
        }
    } else {
        renderForbidden('You do not have permission to perform this action.');
    }
}

if (isset($_GET['profile_saved'])) {
    $success = 'Profile saved. It is hidden from the public directory until its updated details are reviewed.';
} elseif (isset($_GET['reviewed'])) {
    $mapLocationStatus = is_string($_GET['map_location'] ?? null) ? $_GET['map_location'] : '';
    if ($mapLocationStatus === 'added') {
        $success = 'Supplier verified and an approximate map pin was added from its address.';
    } elseif ($mapLocationStatus === 'unavailable') {
        $success = 'Supplier verified, but its address could not be located on the map. Edit the supplier to add a map pin.';
    } else {
        $success = 'Supplier verification status updated.';
    }
} elseif (isset($_GET['linked'])) {
    $success = 'Supplier account link updated.';
}

$directoryQuery = $pdo->query(
    "SELECT id, supplier_name, supplier_type, address, city, region, postal_code, phone, website,
            latitude, longitude, verified_at
     FROM halal_suppliers
     WHERE verification_status = 'verified'
     ORDER BY supplier_name ASC"
);
$directorySuppliers = $directoryQuery->fetchAll();

$supplierProfile = null;
if ($canManageDirectory && isset($_GET['edit_supplier'])) {
    $editSupplierId = filter_var($_GET['edit_supplier'], FILTER_VALIDATE_INT);
    if ($editSupplierId) {
        $pq = $pdo->prepare('SELECT * FROM halal_suppliers WHERE id = ? LIMIT 1');
        $pq->execute([$editSupplierId]);
        $supplierProfile = $pq->fetch() ?: null;
    }
}

$reviewQueue = [];
$supplierAccounts = [];
if ($canManageDirectory) {
    // Pending first so staff see work items at the top.
    $reviewQueue = $pdo->query(
        "SELECT s.id, s.supplier_name, s.supplier_type, s.address, s.city, s.region, s.latitude, s.longitude,
                s.verification_status, s.supplier_user_id, u.name AS supplier_account_name, u.email AS supplier_account_email
         FROM halal_suppliers s
         LEFT JOIN users u ON u.id = s.supplier_user_id
         ORDER BY (s.verification_status = 'verified') ASC, s.supplier_name ASC"
    )->fetchAll();
    $supplierAccounts = $pdo->query(
        "SELECT u.id, u.name, u.email, s.id AS linked_supplier_id
         FROM users u
         LEFT JOIN halal_suppliers s ON s.supplier_user_id = u.id
         WHERE LOWER(u.role) = 'supplier'
         ORDER BY u.name ASC"
    )->fetchAll();
}

$mapRecords = $canManageDirectory ? $reviewQueue : $directorySuppliers;
$formatSupplier = static function (array $supplier): array {
    $website = trim((string) ($supplier['website'] ?? ''));
    $scheme = strtolower((string) parse_url($website, PHP_URL_SCHEME));
    if (!filter_var($website, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
        $website = '';
    }
    $verifiedTimestamp = !empty($supplier['verified_at']) ? strtotime((string) $supplier['verified_at']) : false;
    return [
        'id' => (int) $supplier['id'],
        'name' => (string) $supplier['supplier_name'],
        'type' => (string) $supplier['supplier_type'],
        'address' => trim(implode(', ', array_filter([
            $supplier['address'] ?? '', $supplier['city'] ?? '', $supplier['region'] ?? '', $supplier['postal_code'] ?? '',
        ]))),
        'lat' => is_numeric($supplier['latitude'] ?? null) ? (float) $supplier['latitude'] : null,
        'lng' => is_numeric($supplier['longitude'] ?? null) ? (float) $supplier['longitude'] : null,
        'phone' => (string) ($supplier['phone'] ?? ''),
        'website' => $website,
        'verifiedAt' => $verifiedTimestamp === false ? 'Date not available' : date('M j, Y', $verifiedTimestamp),
        'verified' => ($supplier['verification_status'] ?? 'verified') === 'verified',
    ];
};
$mapSuppliers = array_map($formatSupplier, array_values(array_filter($mapRecords, static function ($s) {
    return is_numeric($s['latitude'] ?? null) && is_numeric($s['longitude'] ?? null)
        && (float) $s['latitude'] >= -90 && (float) $s['latitude'] <= 90
        && (float) $s['longitude'] >= -180 && (float) $s['longitude'] <= 180;
})));
$verifiedDirectorySuppliers = array_map($formatSupplier, $directorySuppliers);
$escape = static fn($v): string => h($v);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verified Halal Suppliers - Halal Heritage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        #supplier-map { position: relative; z-index: 0; isolation: isolate; min-height: 390px; }
        .supplier-card[hidden] { display: none !important; }
        .leaflet-popup-content-wrapper { border-radius: 14px; }
    </style>
</head>
<body class="min-h-screen bg-stone-50 text-stone-800">
    <?php include 'navbar.php'; ?>
    <main class="mx-auto max-w-7xl px-4 py-8 sm:py-10">
        <header class="hh-page-intro rounded-3xl border border-emerald-100 px-6 py-9 shadow-sm sm:px-10">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700"><?= $canManageDirectory ? 'Staff &amp; Admin Tools' : 'Customer Directory' ?></p>
            <div class="mt-3 flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                <div>
                    <h1 class="text-3xl font-extrabold text-emerald-950 sm:text-4xl"><?= $canManageDirectory ? 'Manage halal suppliers' : 'Find verified halal meat suppliers' ?></h1>
                    <p class="mt-3 max-w-3xl leading-relaxed text-stone-700"><?= $canManageDirectory ? 'Add supplier details and approve listings for customers.' : 'Browse farms, butcher shops, and meat suppliers approved by staff.' ?></p>
                </div>
                <a href="<?= $canManageDirectory ? '#supplier-management' : '#supplier-list' ?>" class="inline-flex shrink-0 items-center justify-center rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white transition hover:bg-emerald-700"><?= $canManageDirectory ? 'Manage suppliers' : 'Find a supplier' ?></a>
            </div>
        </header>

        <?php if ($error !== ''): ?><div role="alert" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800"><?= $escape($error) ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div role="status" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900"><?= $escape($success) ?></div><?php endif; ?>

        <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-950">
            <strong>About verification:</strong> staff or an administrator approved each listing before it appears in the directory.
        </section>

        <?php if ($canManageDirectory && $supplierProfile): ?>
            <section id="supplier-profile" class="mt-7 rounded-3xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="mb-5">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-800">Edit listing</p>
                    <h2 class="mt-1 text-2xl font-extrabold text-emerald-950">Edit <?= $escape($supplierProfile['supplier_name']) ?></h2>
                </div>
                <form method="post" class="grid gap-4 sm:grid-cols-2">
                    <input type="hidden" name="csrf_token" value="<?= $escape($supplierCsrf) ?>">
                    <input type="hidden" name="action" value="save_supplier_profile">
                    <input type="hidden" name="target_supplier_id" value="<?= (int) $supplierProfile['id'] ?>">
                    <div>
                        <label class="mb-1 block text-sm font-bold" for="supplier_type">Business type</label>
                        <select id="supplier_type" name="supplier_type" required class="w-full rounded-xl border border-stone-300 bg-white px-3 py-3">
                            <option value="">Choose type</option>
                            <?php foreach ($supplierTypes as $type => $label): ?>
                                <option value="<?= $escape($type) ?>" <?= ($supplierProfile['supplier_type'] ?? '') === $type ? 'selected' : '' ?>><?= $escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold" for="phone">Public phone</label>
                        <input id="phone" name="phone" maxlength="40" value="<?= $escape($supplierProfile['phone'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-3 py-3">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-bold" for="address">Street address</label>
                        <input id="address" name="address" maxlength="255" required value="<?= $escape($supplierProfile['address'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-3 py-3">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold" for="city">City / municipality</label>
                        <input id="city" name="city" maxlength="100" required value="<?= $escape($supplierProfile['city'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-3 py-3">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold" for="region">Province / region</label>
                        <input id="region" name="region" maxlength="100" value="<?= $escape($supplierProfile['region'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-3 py-3">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold" for="postal_code">Postal code</label>
                        <input id="postal_code" name="postal_code" maxlength="20" value="<?= $escape($supplierProfile['postal_code'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-3 py-3">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold" for="website">Website</label>
                        <input id="website" name="website" type="url" maxlength="255" placeholder="https://" value="<?= $escape($supplierProfile['website'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-3 py-3">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold" for="latitude">Latitude</label>
                        <input id="latitude" name="latitude" type="number" step="any" min="-90" max="90" value="<?= $escape($supplierProfile['latitude'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-3 py-3">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold" for="longitude">Longitude</label>
                        <input id="longitude" name="longitude" type="number" step="any" min="-180" max="180" value="<?= $escape($supplierProfile['longitude'] ?? '') ?>" class="w-full rounded-xl border border-stone-300 px-3 py-3">
                    </div>
                    <button type="submit" class="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-extrabold text-white transition hover:bg-emerald-700 sm:col-span-2">Save changes for review</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canManageDirectory): ?>
            <section class="mt-7 rounded-3xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <h2 class="mt-1 text-2xl font-extrabold text-emerald-950">Supplier profiles</h2>
                    <span class="rounded-full bg-stone-100 px-3 py-1 text-xs font-bold text-stone-600"><?= count($reviewQueue) ?> suppliers</span>
                </div>
                <form method="post" class="mb-5 grid gap-3 rounded-2xl bg-stone-50 p-4 sm:grid-cols-2 sm:items-end">
                    <input type="hidden" name="csrf_token" value="<?= $escape($supplierCsrf) ?>">
                    <input type="hidden" name="action" value="create_supplier_profile">
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-name">Supplier name</label>
                        <input id="new-supplier-name" name="supplier_name" required maxlength="100" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-account">Supplier login (optional)</label>
                        <select id="new-supplier-account" name="supplier_user_id" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                            <option value="">Link later</option>
                            <?php foreach ($supplierAccounts as $account): ?>
                                <option value="<?= (int) $account['id'] ?>" <?= $account['linked_supplier_id'] !== null ? 'disabled' : '' ?>><?= $escape($account['name']) ?> (<?= $escape($account['email']) ?>)<?= $account['linked_supplier_id'] !== null ? ' — already linked' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-type">Business type</label>
                        <select id="new-supplier-type" name="supplier_type" required class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm">
                            <option value="">Choose type</option>
                            <?php foreach ($supplierTypes as $type => $label): ?>
                                <option value="<?= $escape($type) ?>"><?= $escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-phone">Public phone</label>
                        <input id="new-supplier-phone" name="phone" maxlength="40" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-address">Street address</label>
                        <input id="new-supplier-address" name="address" maxlength="255" required class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-city">City</label>
                        <input id="new-supplier-city" name="city" maxlength="100" required class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-region">Province / region</label>
                        <input id="new-supplier-region" name="region" maxlength="100" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-postal">Postal code</label>
                        <input id="new-supplier-postal" name="postal_code" maxlength="20" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-website">Website</label>
                        <input id="new-supplier-website" name="website" type="url" maxlength="255" placeholder="https://" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-latitude">Latitude</label>
                        <input id="new-supplier-latitude" name="latitude" type="number" step="any" min="-90" max="90" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold text-stone-700" for="new-supplier-longitude">Longitude</label>
                        <input id="new-supplier-longitude" name="longitude" type="number" step="any" min="-180" max="180" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-2 flex justify-end">
                        <button class="rounded-lg bg-emerald-800 px-4 py-2.5 text-xs font-extrabold text-white hover:bg-emerald-700">Add supplier</button>
                    </div>
                </form>

                <?php if (!$reviewQueue): ?>
                    <p class="rounded-xl bg-stone-50 p-4 text-sm text-stone-600">No suppliers yet.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($reviewQueue as $queued): ?>
                            <article class="rounded-2xl border border-stone-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-extrabold text-emerald-950"><?= $escape($queued['supplier_name']) ?></h3>
                                        <p class="mt-1 text-sm text-stone-600"><?= $escape($supplierTypes[$queued['supplier_type'] ?? ''] ?? 'Business type not added') ?> · <?= $escape(trim(implode(', ', array_filter([$queued['address'], $queued['city'], $queued['region']]))) ?: 'Address not added') ?></p>
                                        <p class="mt-1 text-xs text-stone-500">Status: <?= ($queued['verification_status'] ?? '') === 'verified' ? 'Verified' : 'Needs review' ?></p>
                                    </div>
                                    <div class="space-y-3">
                                        <?php if (($queued['verification_status'] ?? '') === 'verified'): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= $escape($supplierCsrf) ?>">
                                                <input type="hidden" name="action" value="revoke_supplier">
                                                <input type="hidden" name="supplier_id" value="<?= (int) $queued['id'] ?>">
                                                <button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-800 hover:bg-rose-50">Revoke verification</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= $escape($supplierCsrf) ?>">
                                                <input type="hidden" name="action" value="verify_supplier">
                                                <input type="hidden" name="supplier_id" value="<?= (int) $queued['id'] ?>">
                                                <button class="rounded-lg bg-emerald-800 px-4 py-2 text-xs font-extrabold text-white hover:bg-emerald-700">Verify supplier</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" class="max-w-sm space-y-2 rounded-xl bg-stone-50 p-3">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($supplierCsrf) ?>">
                                            <input type="hidden" name="action" value="link_supplier_account">
                                            <input type="hidden" name="supplier_id" value="<?= (int) $queued['id'] ?>">
                                            <label class="block text-xs font-bold text-stone-700">Supplier login</label>
                                            <select name="supplier_user_id" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs">
                                                <option value="">No linked account</option>
                                                <?php foreach ($supplierAccounts as $account): ?>
                                                    <?php $linkedElsewhere = $account['linked_supplier_id'] !== null && (int) $account['linked_supplier_id'] !== (int) $queued['id']; ?>
                                                    <option value="<?= (int) $account['id'] ?>" <?= (int) ($queued['supplier_user_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?> <?= $linkedElsewhere ? 'disabled' : '' ?>><?= $escape($account['name']) ?> (<?= $escape($account['email']) ?>)<?= $linkedElsewhere ? ' — already linked' : '' ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="rounded-lg border border-emerald-800 px-3 py-2 text-xs font-bold text-emerald-900 hover:bg-emerald-100">Save login</button>
                                        </form>
                                        <a href="halal_suppliers.php?edit_supplier=<?= (int) $queued['id'] ?>#supplier-profile" class="inline-flex text-xs font-bold text-emerald-800 hover:underline">Add or edit supplier details</a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(300px,.65fr)]">
            <div class="overflow-hidden rounded-3xl border border-stone-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-4">
                    <h2 class="font-extrabold text-emerald-950">Supplier map</h2>
                    <button type="button" id="locate-me" class="rounded-lg border border-emerald-200 px-3 py-2 text-xs font-bold text-emerald-900 transition hover:bg-emerald-50">Use my location</button>
                </div>
                <div id="supplier-map" class="relative" role="region" aria-label="Map of halal supplier locations">
                    <div id="map-empty" class="pointer-events-none absolute inset-x-3 top-3 z-[1000] mx-auto max-w-sm rounded-xl border border-stone-200 bg-white/95 p-4 text-center text-sm text-stone-700 shadow-lg" hidden>
                        <strong class="block text-emerald-950">No supplier pins yet</strong>
                    </div>
                </div>
                <p id="location-status" class="px-5 py-3 text-xs text-stone-500" aria-live="polite">Your location is not being used.</p>
            </div>
        </section>

        <section id="supplier-list" class="mt-9">
            <div class="mb-5 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div>
                    <h2 class="mt-1 text-2xl font-extrabold text-emerald-950"><?= $canManageDirectory ? 'Approved suppliers customers can see' : 'Verified halal suppliers' ?></h2>
                    <p id="supplier-count" class="mt-1 text-sm text-stone-600"><?= count($verifiedDirectorySuppliers) ?> suppliers listed</p>
                </div>
                <div class="grid gap-2 sm:grid-cols-3">
                    <input id="supplier-search" type="search" placeholder="Search name, city..." class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm">
                    <select id="supplier-type-filter" class="rounded-xl border border-stone-300 bg-white px-3 py-2.5 text-sm">
                        <option value="">All types</option>
                        <?php foreach ($supplierTypes as $type => $label): ?>
                            <option value="<?= $escape($type) ?>"><?= $escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="supplier-radius" disabled class="rounded-xl border border-stone-300 bg-stone-100 px-3 py-2.5 text-sm disabled:cursor-not-allowed disabled:opacity-60">
                        <option value="0">Any distance</option>
                        <option value="5">Within 5 km</option>
                        <option value="10">Within 10 km</option>
                        <option value="25">Within 25 km</option>
                        <option value="50">Within 50 km</option>
                    </select>
                </div>
            </div>

            <?php if (!$verifiedDirectorySuppliers): ?>
                <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center">
                    <h3 class="text-lg font-extrabold text-emerald-950">No verified suppliers listed yet</h3>
                </div>
            <?php else: ?>
                <div id="supplier-cards" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($verifiedDirectorySuppliers as $supplier): ?>
                        <article class="supplier-card rounded-2xl border border-stone-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                            data-id="<?= (int) $supplier['id'] ?>"
                            data-name="<?= $escape(mb_strtolower($supplier['name'])) ?>"
                            data-type="<?= $escape($supplier['type']) ?>"
                            data-search="<?= $escape(mb_strtolower(implode(' ', [$supplier['name'], $supplier['address']]))) ?>"
                            data-lat="<?= $supplier['lat'] === null ? '' : $escape($supplier['lat']) ?>"
                            data-lng="<?= $supplier['lng'] === null ? '' : $escape($supplier['lng']) ?>">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-extrabold text-emerald-900">&#10003; Staff verified</span>
                                    <h3 class="mt-3 text-lg font-extrabold text-emerald-950"><?= $escape($supplier['name']) ?></h3>
                                    <p class="mt-1 text-sm font-semibold text-stone-600"><?= $escape($supplierTypes[$supplier['type']] ?? 'Supplier') ?></p>
                                </div>
                                <span class="distance-label hidden shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-950"></span>
                            </div>
                            <p class="mt-4 text-sm leading-relaxed text-stone-600"><?= $escape($supplier['address'] ?: 'Address not provided') ?></p>
                            <?php if ($supplier['phone'] !== ''): ?>
                                <a href="tel:<?= $escape(preg_replace('/[^0-9+]/', '', $supplier['phone'])) ?>" class="mt-3 inline-flex text-sm font-bold text-emerald-800 hover:underline"><?= $escape($supplier['phone']) ?></a>
                            <?php endif; ?>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <?php if ($supplier['address'] !== ''): ?>
                                    <button type="button" class="show-on-map rounded-lg bg-emerald-800 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700" data-id="<?= (int) $supplier['id'] ?>" data-address="<?= $escape($supplier['address']) ?>"><?= $supplier['lat'] !== null ? 'Show on map' : 'Find on map' ?></button>
                                <?php endif; ?>
                                <?php if ($supplier['website'] !== ''): ?>
                                    <a target="_blank" rel="noopener noreferrer" href="<?= $escape($supplier['website']) ?>" class="rounded-lg border border-stone-300 px-3 py-2 text-xs font-bold text-stone-700 hover:bg-stone-50">Website</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
    (() => {
        const suppliers = <?= jsJson($mapSuppliers) ?>;
        const mapElement = document.getElementById('supplier-map');
        if (!mapElement || typeof L === 'undefined') {
            document.getElementById('location-status').textContent = 'The map could not load. You can still use the addresses in the supplier list.';
            return;
        }
        const map = L.map(mapElement, { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
        const markers = new Map();
        const markerBounds = [];
        const supplierPin = L.divIcon({
            className: 'halal-supplier-pin',
            html: '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="46" viewBox="0 0 36 46" aria-hidden="true"><path d="M18 1C8.6 1 1 8.5 1 17.7c0 12.1 17 27.3 17 27.3s17-15.2 17-27.3C35 8.5 27.4 1 18 1Z" fill="#047857" stroke="#fff" stroke-width="2"/><circle cx="18" cy="17" r="6" fill="#fff"/><circle cx="18" cy="17" r="3" fill="#047857"/></svg>',
            iconSize: [36, 46], iconAnchor: [18, 45], popupAnchor: [0, -42],
        });
        suppliers.forEach(supplier => {
            const marker = L.marker([supplier.lat, supplier.lng], { icon: supplierPin, title: supplier.name, alt: supplier.name + ' supplier location' }).addTo(map);
            const popup = document.createElement('div');
            const title = document.createElement('strong'); title.textContent = supplier.name;
            const addr = document.createElement('p'); addr.textContent = supplier.address || 'Address not provided';
            const note = document.createElement('p'); note.textContent = 'Map pin may be approximate.';
            popup.append(title, addr, note);
            marker.bindPopup(popup);
            markers.set(String(supplier.id), marker);
            markerBounds.push([supplier.lat, supplier.lng]);
        });
        document.getElementById('map-empty').hidden = markerBounds.length > 0;
        if (markerBounds.length) {
            map.fitBounds(markerBounds, { padding: [30, 30], maxZoom: 12 });
        } else {
            map.setView([12.8797, 121.7740], 5);
        }

        const cards = Array.from(document.querySelectorAll('.supplier-card'));
        const searchInput = document.getElementById('supplier-search');
        const typeFilter = document.getElementById('supplier-type-filter');
        const radiusFilter = document.getElementById('supplier-radius');
        const countLabel = document.getElementById('supplier-count');
        const locationStatus = document.getElementById('location-status');
        let userLocation = null;

        const distanceKm = (lat1, lng1, lat2, lng2) => {
            const rad = d => d * Math.PI / 180;
            const dLat = rad(lat2 - lat1), dLng = rad(lng2 - lng1);
            const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2;
            return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        };

        const updateDirectory = () => {
            const search = searchInput.value.trim().toLocaleLowerCase();
            const type = typeFilter.value;
            const radius = Number(radiusFilter.value);
            let visibleCount = 0;
            cards.forEach(card => {
                const hasCoords = card.dataset.lat !== '' && card.dataset.lng !== '';
                const distance = userLocation && hasCoords
                    ? distanceKm(userLocation.lat, userLocation.lng, Number(card.dataset.lat), Number(card.dataset.lng))
                    : null;
                const matches = (!search || card.dataset.search.includes(search))
                    && (!type || card.dataset.type === type)
                    && (!radius || distance === null || distance <= radius);
                card.hidden = !matches;
                const marker = markers.get(card.dataset.id);
                if (marker && matches && !map.hasLayer(marker)) marker.addTo(map);
                else if (marker && !matches && map.hasLayer(marker)) map.removeLayer(marker);
                if (matches) {
                    visibleCount++;
                    if (distance !== null) {
                        const lbl = card.querySelector('.distance-label');
                        lbl.textContent = distance < 1 ? Math.round(distance * 1000) + ' m away' : distance.toFixed(1) + ' km away';
                        lbl.classList.remove('hidden');
                    }
                }
            });
            countLabel.textContent = visibleCount + ' suppliers listed';
        };

        [searchInput, typeFilter, radiusFilter].forEach(el => el.addEventListener('input', updateDirectory));
        document.getElementById('locate-me').addEventListener('click', () => {
            if (!navigator.geolocation) { locationStatus.textContent = 'Location not available.'; return; }
            locationStatus.textContent = 'Asking your browser for permission…';
            navigator.geolocation.getCurrentPosition((position) => {
                userLocation = { lat: position.coords.latitude, lng: position.coords.longitude };
                radiusFilter.disabled = false;
                map.setView([userLocation.lat, userLocation.lng], 11);
                L.circleMarker([userLocation.lat, userLocation.lng], { radius: 8, color: '#1d4ed8', fillColor: '#60a5fa', fillOpacity: 0.9 }).addTo(map).bindPopup('Your approximate location');
                locationStatus.textContent = 'Location is on. Choose a distance filter to narrow the list.';
                updateDirectory();
            }, () => { locationStatus.textContent = 'Could not access your location.'; }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
        });
    })();
    </script>
</body>
</html>