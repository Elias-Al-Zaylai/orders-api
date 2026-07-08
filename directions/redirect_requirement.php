<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';

requirePermission('redirect_requirement');

$data = json_decode(file_get_contents("php://input"), true);

$requirement_id = $data['requirement_id'] ?? null;
$executor_id = $data['executor_id'] ?? null;
$notes_to_executor = trim($data['notes_to_executor'] ?? $data['executor_note'] ?? '');
$allowed_start_date = $data['allowed_start'] ?? $data['allowed_start_date'] ?? null;
$allowed_end_date = $data['allowed_end'] ?? $data['allowed_end_date'] ?? null;

if (
    empty($requirement_id) ||
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
    SELECT id, order_id, status
    FROM requirements
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$requirement_id]);
$requirement = $stmt->fetch();

if (!$requirement) {
    http_response_code(404);
    echo json_encode([
        "status" => false,
        "message" => "المطلوب غير موجود"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedStatuses = [
    'received_by_executor',
    'returned_to_executor'
];

if (!authUserHasRole('admin') && !in_array($requirement['status'], $allowedStatuses)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "لا يمكن إعادة توجيه المطلوب في حالته الحالية"
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
        "message" => "المنفذ الجديد غير موجود أو غير فعال"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare("
        INSERT INTO requirement_directions (
            requirement_id,
            directed_by_user_id,
            executor_id,
            notes_to_executor,
            allowed_start,
            allowed_end,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        RETURNING id
    ");

    $insert->execute([
        $requirement_id,
        $authUser['id'],
        $executor_id,
        $notes_to_executor,
        $allowed_start_date,
        $allowed_end_date
    ]);

    $directionId = (int) $insert->fetchColumn();

    $updateRequirement = $pdo->prepare("
        UPDATE requirements
        SET status = 'directed',
            updated_at = NOW()
        WHERE id = ?
    ");

    $updateRequirement->execute([$requirement_id]);

    // تحديث حالة الطلب تلقائيًا بعد إعادة التوجيه
    $newOrderStatus = updateOrderStatus($pdo, (int) $requirement['order_id']);

    $notify = $pdo->prepare("
        INSERT INTO notifications (
            user_id,
            title,
            message,
            is_read,
            created_at
        )
        VALUES (?, ?, ?, FALSE, NOW())
    ");

    $notify->execute([
        $executor_id,
        'مطلوب معاد توجيهه إليك',
        'تم إعادة توجيه مطلوب إليك، يرجى مراجعته.'
    ]);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم إعادة توجيه المطلوب بنجاح",
        "direction_id" => $directionId,
        "order_status" => $newOrderStatus
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $pdo->rollBack();

    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "فشل إعادة التوجيه",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}