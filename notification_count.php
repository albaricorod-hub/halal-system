<?php
require_once 'config.php';
checkAuth();
ensureNotificationArchiveColumn($pdo);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_deleted = 0');
$stmt->execute([(int) $_SESSION['user_id']]);
echo json_encode(['count' => (int) $stmt->fetchColumn()], JSON_THROW_ON_ERROR);