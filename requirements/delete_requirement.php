<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';

// صلاحية حذف المطلوب
requirePermission('delete_requirement');

$data = json_decode(file_get_contents("php://input"), true);
$requirementId = (int) ($data['requirement_id'] ?? 0);

if ($requirementId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare(" 
        SELECT
            r.id,
            r.order_id,
            r.status,
            o.requester_id
        FROM requirements r
        INNER JOIN orders o ON o.id = r.order_id
        WHERE r.id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $statement->execute([$requirementId]);
    $item = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new Exception("المطلوب غير موجود");
    }

    $isAdmin = authUserHasRole('admin');
    $isRequester = (int) $item['requester_id'] === (int) $authUser['id'];

    if (!$isAdmin && !$isRequester) {
        throw new Exception("غير مسموح لك بحذف هذا المطلوب");
    }

    // مقدم الطلب يحذف فقط المطلوب الجديد
    if (!$isAdmin && $item['status'] !== 'new') {
        throw new Exception("لا يمكن حذف المطلوب بعد التوجيه");
    }

    $directionCheck = $pdo->prepare(" 
        SELECT COUNT(*)
        FROM requirement_directions
        WHERE requirement_id = ?
    ");

    $directionCheck->execute([$requirementId]);
    $hasDirections = (int) $directionCheck->fetchColumn() > 0;

    if (!$isAdmin && $hasDirections) {
        throw new Exception("لا يمكن حذف مطلوب تم توجيهه");
    }

    // حذف البيانات التابعة بالترتيب الصحيح
    $pdo->prepare("DELETE FROM receipt_approvals WHERE requirement_id = ?")
        ->execute([$requirementId]);

    $pdo->prepare("DELETE FROM execution_receipts WHERE requirement_id = ?")
        ->execute([$requirementId]);

    $pdo->prepare("DELETE FROM requirement_actions WHERE requirement_id = ?")
        ->execute([$requirementId]);

    $pdo->prepare("DELETE FROM requirement_directions WHERE requirement_id = ?")
        ->execute([$requirementId]);

    $pdo->prepare("DELETE FROM requirements WHERE id = ?")
        ->execute([$requirementId]);

    $newOrderStatus = updateOrderStatus($pdo, (int) $item['order_id']);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم حذف المطلوب بنجاح",
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
