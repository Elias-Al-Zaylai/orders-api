<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';

$stmt = $pdo->prepare("
    SELECT id, name
    FROM companies
    WHERE is_active = TRUE
    ORDER BY name ASC
");

$stmt->execute();

echo json_encode([
    "status" => true,
    "companies" => $stmt->fetchAll()
], JSON_UNESCAPED_UNICODE);