<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('direct_requirement');

$data = json_decode(file_get_contents("php://input"), true);

$direction_id = $data['direction_id'] ?? null;
$executor_id = $data['executor_id'] ?? null;
$notes_to_executor = trim($data['notes_to_executor'] ?? '');
$allowed_start_date = $data['allowed_start'] ?? $data['allowed_start_date'] ?? null;
$allowed_end_date = $data['allowed_end'] ?? $data['allowed_end_date'] ?? null;

if (
    empty($direction_id) ||
    empty($executor_id) ||
    empty($allowed_start_date) ||
    empty($allowed_end_date)
) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المطلوبة ناقصة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$stmt = $pdo->prepare("
    SELECT
        rd.id AS direction_id,
        rd.requirement_id,
        r.status AS requirement_status
    FROM requirement_directions rd
    INNER JOIN requirements r ON r.id = rd.requirement_id
    WHERE rd.id = ?
    LIMIT 1
");

$stmt->execute([$direction_id]);
$direction = $stmt->fetch();

if (!$direction) {
    http_response_code(404);

    echo json_encode([
        "status" => false,
        "message" => "التوجيه غير موجود"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
مدير التوجيه يعدل فقط إذا الحالة directed
مدير النظام يعدل في أي وقت
*/
if (!authUserHasRole('admin') && $direction['requirement_status'] !== 'directed') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "لا يمكن تعديل التوجيه بعد استلام المنفذ للمطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$checkExecutor = $pdo->prepare("
    SELECT u.id
    FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE u.id = ?
      AND r.name = 'executor'
      AND r.is_active = TRUE
      AND u.is_active = TRUE
    LIMIT 1
");

$checkExecutor->execute([$executor_id]);

if (!$checkExecutor->fetch()) {
    http_response_code(404);

    echo json_encode([
        "status" => false,
        "message" => "المنفذ غير موجود أو غير فعال"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$update = $pdo->prepare("
    UPDATE requirement_directions
    SET
        executor_id = ?,
        notes_to_executor = ?,
        allowed_start = ?,
        allowed_end = ?,
        updated_at = NOW()
    WHERE id = ?
");

$update->execute([
    $executor_id,
    $notes_to_executor,
    $allowed_start_date,
    $allowed_end_date,
    $direction_id
]);

echo json_encode([
    "status" => true,
    "message" => "تم تعديل التوجيه بنجاح"
], JSON_UNESCAPED_UNICODE);