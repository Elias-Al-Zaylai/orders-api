<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';

$stmt = $pdo->prepare("
    SELECT
        id,
        title,
        message,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY id DESC
");

$stmt->execute([$authUser['id']]);

$notifications = $stmt->fetchAll();

echo json_encode([
    "status" => true,
    "count" => count($notifications),
    "notifications" => $notifications
], JSON_UNESCAPED_UNICODE);