<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';

requirePermission('redirect_requirement');

$data = json_decode(file_get_contents("php://input"), true);

$requirementId = (int) ($data['requirement_id'] ?? 0);
$executorId = (int) ($data['executor_id'] ?? 0);
$notesToExecutor = trim($data['notes_to_executor'] ?? $data['executor_note'] ?? '');
$allowedStart = $data['allowed_start'] ?? $data['allowed_start_date'] ?? null;
$allowedEnd = $data['allowed_end'] ?? $data['allowed_end_date'] ?? null;

if ($requirementId <= 0 || $executorId <= 0 || empty($allowedStart) || empty($allowedEnd)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المطلوبة ناقصة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $isAdmin = authUserHasRole('admin');
    $departmentId = (int) ($authUser['department_id'] ?? 0);

    if (!$isAdmin && $departmentId <= 0) {
        throw new Exception("لا توجد إدارة مرتبطة بالموجّه الحالي");
    }

    $whereDepartment = '';
    $params = [$requirementId];

    if (!$isAdmin) {
        $whereDepartment = "AND o.to_department_id = ?";
        $params[] = $departmentId;
    }

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.order_id,
            r.status

        FROM requirements r

        INNER JOIN orders o
            ON o.id = r.order_id

        WHERE r.id = ?
          $whereDepartment

        LIMIT 1
    ");

    $stmt->execute($params);
    $requirement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$requirement) {
        throw new Exception("المطلوب غير موجود أو لا يتبع إدارة الموجّه");
    }

    $allowedStatuses = [
        'received_by_executor',
        'returned_to_executor'
    ];

    if (!$isAdmin && !in_array($requirement['status'], $allowedStatuses, true)) {
        throw new Exception("لا يمكن إعادة توجيه المطلوب في حالته الحالية");
    }

    $executorDepartmentSql = '';
    $executorParams = [$executorId];

    if (!$isAdmin) {
        $executorDepartmentSql = "AND u.department_id = ?";
        $executorParams[] = $departmentId;
    }

    $checkExecutor = $pdo->prepare("
        SELECT DISTINCT u.id

        FROM users u

        INNER JOIN user_roles ur
            ON ur.user_id = u.id

        INNER JOIN roles r
            ON r.id = ur.role_id

        WHERE u.id = ?
          AND r.name = 'executor'
          AND r.is_active = TRUE
          AND u.is_active = TRUE
          $executorDepartmentSql

        LIMIT 1
    ");

    $checkExecutor->execute($executorParams);

    if (!$checkExecutor->fetch()) {
        throw new Exception("المنفذ الجديد غير موجود أو غير فعال أو لا يتبع نفس الإدارة");
    }

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
        $requirementId,
        $authUser['id'],
        $executorId,
        $notesToExecutor,
        $allowedStart,
        $allowedEnd
    ]);

    $directionId = (int) $insert->fetchColumn();

    $updateRequirement = $pdo->prepare("
        UPDATE requirements
        SET
            status = 'directed',
            updated_at = NOW()
        WHERE id = ?
    ");

    $updateRequirement->execute([$requirementId]);

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
        $executorId,
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

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
