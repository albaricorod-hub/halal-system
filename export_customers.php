<?php
require_once 'config.php';
checkAuth(['admin', 'staff']);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=registered_customers_' . date('Y-m-d') . '.csv');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'w');
fputcsv($output, ['Customer ID', 'Full Name', 'Email Address', 'Phone', 'Gender', 'Address', 'Account Status', 'Registration Date']);

$csvSafe = static function ($v): string {
    $v = (string) $v;
    if ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v)) {
        return "'" . $v;
    }
    return $v;
};

$stmt = $pdo->query("SELECT id, name, email, contact_number, gender, address, status, created_at FROM users WHERE LOWER(role) = 'customer' ORDER BY created_at DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $csvSafe($row['name']),
        $csvSafe($row['email']),
        $csvSafe($row['contact_number'] ?? ''),
        $csvSafe($row['gender'] ?? ''),
        $csvSafe($row['address'] ?? ''),
        $csvSafe($row['status'] ?? ''),
        $row['created_at'],
    ]);
}
fclose($output);
exit;