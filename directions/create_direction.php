<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';
require_once __DIR__ . '/../helpers/notification_helper.php';

// السماح فقط لمن يمتلك صلاحية توجيه المطاليب
requirePermission('direct_requirement');

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "بيانات الطلب غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$requirementId = (int) ($data['requirement_id'] ?? 0);
$executorId = (int) ($data['executor_id'] ?? 0);
$notesToExecutor = trim($data['notes_to_executor'] ?? '');
$allowedStart = $data['allowed_start'] ?? null;
$allowedEnd = $data['allowed_end'] ?? null;

if ($requirementId <= 0 || $executorId <= 0 || empty($allowedStart) || empty($allowedEnd)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب والمنفذ وبداية ونهاية التنفيذ مطلوبة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$allowedStartTimestamp = strtotime($allowedStart);
$allowedEndTimestamp = strtotime($allowedEnd);

if ($allowedStartTimestamp === false || $allowedEndTimestamp === false) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "صيغة تاريخ بداية أو نهاية التنفيذ غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($allowedEndTimestamp <= $allowedStartTimestamp) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ نهاية التنفيذ يجب أن يكون بعد تاريخ البداية"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

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

    /*
     * جلب المطلوب وقفل السجل، مع التأكد أنه تابع لإدارة الموجّه.
     */
    $requirementStatement = $pdo->prepare("
        SELECT
            r.id,
            r.order_id,
            r.problem,
            r.status,
            o.to_department_id

        FROM requirements r

        INNER JOIN orders o
            ON o.id = r.order_id

        WHERE r.id = ?
          $whereDepartment

        LIMIT 1

        FOR UPDATE
    ");

    $requirementStatement->execute($params);
    $requirement = $requirementStatement->fetch(PDO::FETCH_ASSOC);

    if (!$requirement) {
        throw new Exception("المطلوب غير موجود أو لا يتبع إدارة الموجّه");
    }

    if ($requirement['status'] !== 'new') {
        throw new Exception("هذا المطلوب تم توجيهه سابقًا أو حالته لا تسمح بالتوجيه");
    }

    $orderId = (int) $requirement['order_id'];

    /*
     * التأكد أن المنفذ نشط ويمتلك صلاحية التنفيذ
     * وأنه من نفس إدارة الموجّه فقط.
     */
    $executorDepartmentSql = '';
    $executorParams = [$executorId];

    if (!$isAdmin) {
        $executorDepartmentSql = "AND u.department_id = ?";
        $executorParams[] = $departmentId;
    }

    $executorStatement = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.name

        FROM users u

        INNER JOIN user_roles ur
            ON ur.user_id = u.id

        INNER JOIN role_permissions rp
            ON rp.role_id = ur.role_id

        INNER JOIN permissions p
            ON p.id = rp.permission_id

        WHERE u.id = ?
          AND u.is_active = TRUE
          AND p.permission_key = 'execute_requirement'
          $executorDepartmentSql

        LIMIT 1
    ");

    $executorStatement->execute($executorParams);
    $executor = $executorStatement->fetch(PDO::FETCH_ASSOC);

    if (!$executor) {
        throw new Exception("المنفذ غير موجود أو غير فعال أو لا يتبع نفس الإدارة");
    }

    /*
     * منع اختيار منفذ مشغول بمطلوب لم ينتهِ بعد.
     */
    $busyExecutorStatement = $pdo->prepare("
        SELECT
            r.id,
            r.problem,
            r.status

        FROM requirement_directions rd

        INNER JOIN requirements r
            ON r.id = rd.requirement_id

        WHERE rd.executor_id = ?
          AND r.status IN (
              'directed',
              'received_by_executor',
              'returned_to_executor'
          )

        LIMIT 1

        FOR UPDATE
    ");

    $busyExecutorStatement->execute([$executorId]);
    $busyRequirement = $busyExecutorStatement->fetch(PDO::FETCH_ASSOC);

    if ($busyRequirement) {
        throw new Exception("هذا المنفذ مشغول حاليًا بمطلوب آخر");
    }

    $directionStatement = $pdo->prepare("
        SELECT id
        FROM requirement_directions
        WHERE requirement_id = ?
        LIMIT 1
    ");

    $directionStatement->execute([$requirementId]);

    if ($directionStatement->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception("تم توجيه هذا المطلوب سابقًا");
    }

    $insertDirection = $pdo->prepare("
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

    $insertDirection->execute([
        $requirementId,
        $authUser['id'],
        $executorId,
        $notesToExecutor,
        date('Y-m-d H:i:s', $allowedStartTimestamp),
        date('Y-m-d H:i:s', $allowedEndTimestamp)
    ]);

    $directionId = (int) $insertDirection->fetchColumn();

    $updateRequirement = $pdo->prepare("
        UPDATE requirements
        SET
            status = 'directed',
            updated_at = NOW()
        WHERE id = ?
          AND status = 'new'
    ");

    $updateRequirement->execute([$requirementId]);

    if ($updateRequirement->rowCount() === 0) {
        throw new Exception("تعذر تحديث حالة المطلوب");
    }

    $newOrderStatus = updateOrderStatus($pdo, $orderId);

    $remainingNewStatement = $pdo->prepare("
        SELECT COUNT(*)
        FROM requirements
        WHERE order_id = ?
          AND status = 'new'
    ");

    $remainingNewStatement->execute([$orderId]);
    $newRequirements = (int) $remainingNewStatement->fetchColumn();

    sendNotification(
        $pdo,
        $executorId,
        'مطلوب جديد موجه إليك',
        'تم توجيه مطلوب جديد إليك، يرجى مراجعة تفاصيله.'
    );

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم توجيه المطلوب إلى المنفذ بنجاح",
        "direction_id" => $directionId,
        "requirement_id" => $requirementId,
        "order_id" => $orderId,
        "order_status" => $newOrderStatus,
        "remaining_new_requirements" => $newRequirements,
        "executor" => [
            "id" => (int) $executor['id'],
            "name" => $executor['name']
        ]
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
