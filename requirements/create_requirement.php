<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('create_requirement');

$data = json_decode(file_get_contents("php://input"), true);

$order_id = $data['order_id'] ?? null;
$requirement = trim($data['requirement'] ?? '');
$problem = trim($data['problem'] ?? '');

if (empty($order_id) || empty($requirement) || empty($problem)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم الطلب والمطلوب والمشكلة مطلوبة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$stmt = $pdo->prepare("
    SELECT id
    FROM orders
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);

    echo json_encode([
        "status" => false,
        "message" => "الطلب غير موجود"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$insert = $pdo->prepare("
    INSERT INTO requirements (
        order_id,
        requirement,
        problem,
        status,
        created_at,
        updated_at
    )
    VALUES (?, ?, ?, 'new', NOW(), NOW())
    RETURNING id
");

$insert->execute([
    $order_id,
    $requirement,
    $problem
]);

$requirementId = (int) $insert->fetchColumn();

echo json_encode([
    "status" => true,
    "message" => "تم إنشاء المطلوب بنجاح",
    "requirement_id" => $requirementId
], JSON_UNESCAPED_UNICODE);