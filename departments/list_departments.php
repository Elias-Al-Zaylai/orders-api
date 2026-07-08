<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';

$company_id = $_GET['company_id'] ?? null;

if (empty($company_id)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "رقم الشركة مطلوب"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name
    FROM departments
    WHERE is_active = TRUE
    AND company_id = ?
    ORDER BY name ASC
");

$stmt->execute([$company_id]);

echo json_encode([
    "status" => true,
    "departments" => $stmt->fetchAll()
], JSON_UNESCAPED_UNICODE);