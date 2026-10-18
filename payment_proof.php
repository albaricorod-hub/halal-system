<?php
require_once 'config.php';
checkAuth();
ensurePaymentProofColumn($pdo);

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$orderId) {
    http_response_code(400);
    exit('Invalid order.');
}

$userId = (int) $_SESSION['user_id'];
$userRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$proofStmt = $pdo->prepare('SELECT o.user_id, p.proof_path FROM orders o JOIN payments p ON p.order_id = o.id WHERE o.id = ?');
$proofStmt->execute([$orderId]);
$payment = $proofStmt->fetch();
if (!$payment || ((int) $payment['user_id'] !== $userId && !in_array($userRole, ['admin', 'staff'], true))) {
    http_response_code(404);
    exit('Payment screenshot not found.');
}

$proofName = basename((string) ($payment['proof_path'] ?? ''));
if (!preg_match('/^payment_[a-f0-9]{32}\.(jpg|png|webp)$/', $proofName)) {
    http_response_code(404);
    exit('Payment screenshot not found.');
}

$proofPath = __DIR__ . '/uploads/payment_proofs/' . $proofName;
if (!is_file($proofPath)) {
    http_response_code(404);
    exit('Payment screenshot not found.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    http_response_code(500);
    exit('Unable to verify payment screenshot.');
}
$mimeType = finfo_file($finfo, $proofPath);
finfo_close($finfo);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit('Unsupported payment screenshot.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($proofPath));
header('Content-Disposition: inline; filename="payment-proof-' . $orderId . '.' . pathinfo($proofName, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($proofPath);