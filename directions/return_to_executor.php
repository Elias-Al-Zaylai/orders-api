<?php

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';
require_once __DIR__ . '/../helpers/notification_helper.php';

// مدير التوجيه يمتلك صلاحية الاعتماد والإرجاع
requirePermission('approve_receipt');

$data = json_decode(file_get_contents("php://input"), true);

$requirementId = (int) ($data['requirement_id'] ?? 0);
$returnReason = trim($data['return_reason'] ?? '');

if ($requirementId <= 0 || $returnReason === '') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب وسبب الإعادة مطلوبان"
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

    $statement = $pdo->prepare("
        SELECT
            r.id,
            r.order_id,
            r.status,
            rd.executor_id

        FROM requirements r

        INNER JOIN orders o
            ON o.id = r.order_id

        INNER JOIN requirement_directions rd
            ON rd.requirement_id = r.id

        WHERE r.id = ?
          $whereDepartment

        ORDER BY rd.id DESC

        LIMIT 1

        FOR UPDATE
    ");

    $statement->execute($params);
    $requirement = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$requirement) {
        throw new Exception("المطلوب أو بيانات التوجيه غير موجودة أو لا تتبع إدارة الموجّه");
    }

    if ($requirement['status'] !== 'received_by_requester') {
        throw new Exception("حالة المطلوب لا تسمح بإعادته إلى المنفذ");
    }

    if (empty($requirement['executor_id'])) {
        throw new Exception("لا يوجد منفذ مرتبط بالمطلوب");
    }

    $updateRequirement = $pdo->prepare("
        UPDATE requirements
        SET
            status = 'returned_to_executor',
            updated_at = NOW()
        WHERE id = ?
    ");

    $updateRequirement->execute([$requirementId]);

    $updateDirection = $pdo->prepare("
        UPDATE requirement_directions
        SET
            return_reason = ?,
            returned_at = NOW(),
            returned_by_user_id = ?,
            updated_at = NOW()
        WHERE requirement_id = ?
    ");

    $updateDirection->execute([
        $returnReason,
        $authUser['id'],
        $requirementId
    ]);

    $newOrderStatus = updateOrderStatus($pdo, (int) $requirement['order_id']);

    sendNotification(
        $pdo,
        (int) $requirement['executor_id'],
        'تمت إعادة مطلوب إليك',
        'تمت إعادة المطلوب لاستكمال التنفيذ. السبب: ' . $returnReason
    );

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تمت إعادة المطلوب إلى المنفذ بنجاح",
        "requirement_id" => $requirementId,
        "requirement_status" => "returned_to_executor",
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
