<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('direct_requirement');

$data = json_decode(file_get_contents("php://input"), true);

$directionId = (int) ($data['direction_id'] ?? 0);
$executorId = (int) ($data['executor_id'] ?? 0);
$notesToExecutor = trim($data['notes_to_executor'] ?? '');
$allowedStart = $data['allowed_start'] ?? $data['allowed_start_date'] ?? null;
$allowedEnd = $data['allowed_end'] ?? $data['allowed_end_date'] ?? null;

if ($directionId <= 0 || $executorId <= 0 || empty($allowedStart) || empty($allowedEnd)) {
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
    $params = [$directionId];

    if (!$isAdmin) {
        $whereDepartment = "AND o.to_department_id = ?";
        $params[] = $departmentId;
    }

    $stmt = $pdo->prepare("
        SELECT
            rd.id AS direction_id,
            rd.requirement_id,
            r.status AS requirement_status

        FROM requirement_directions rd

        INNER JOIN requirements r
            ON r.id = rd.requirement_id

        INNER JOIN orders o
            ON o.id = r.order_id

        WHERE rd.id = ?
          $whereDepartment

        LIMIT 1
    ");

    $stmt->execute($params);
    $direction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$direction) {
        throw new Exception("التوجيه غير موجود أو لا يتبع إدارة الموجّه");
    }

    if (!$isAdmin && $direction['requirement_status'] !== 'directed') {
        throw new Exception("لا يمكن تعديل التوجيه بعد استلام المنفذ للمطلوب");
    }

    /*
     * المنفذ الجديد يجب أن يكون من نفس إدارة الموجّه.
     */
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
        throw new Exception("المنفذ غير موجود أو غير فعال أو لا يتبع نفس الإدارة");
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
        $executorId,
        $notesToExecutor,
        $allowedStart,
        $allowedEnd,
        $directionId
    ]);

    echo json_encode([
        "status" => true,
        "message" => "تم تعديل التوجيه بنجاح"
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
