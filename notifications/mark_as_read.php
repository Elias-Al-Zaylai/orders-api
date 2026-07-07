<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';

$data = json_decode(file_get_contents("php://input"), true);

$notification_id = $data['notification_id'] ?? null;

if (empty($notification_id)) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم الإشعار مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$stmt = $pdo->prepare("
    SELECT id
    FROM notifications
    WHERE id = ?
    AND user_id = ?
    LIMIT 1
");

$stmt->execute([
    $notification_id,
    $authUser['id']
]);

if (!$stmt->fetch()) {

    http_response_code(404);

    echo json_encode([
        "status" => false,
        "message" => "الإشعار غير موجود"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$update = $pdo->prepare("
    UPDATE notifications
    SET is_read = TRUE
    WHERE id = ?
");

$update->execute([$notification_id]);

echo json_encode([
    "status" => true,
    "message" => "تم تعليم الإشعار كمقروء"
], JSON_UNESCAPED_UNICODE);