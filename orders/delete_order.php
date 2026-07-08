<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية حذف الطلب
requirePermission('delete_order');

$data = json_decode(file_get_contents("php://input"), true);
$orderId = (int) ($data['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم الطلب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

    $orderStatement = $pdo->prepare(" 
        SELECT id, requester_id, status
        FROM orders
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $orderStatement->execute([$orderId]);
    $order = $orderStatement->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("الطلب غير موجود");
    }

    $isAdmin = authUserHasRole('admin');
    $isRequester = (int) $order['requester_id'] === (int) $authUser['id'];

    if (!$isAdmin && !$isRequester) {
        throw new Exception("غير مسموح لك بحذف هذا الطلب");
    }

    // مقدم الطلب يحذف فقط قبل التوجيه
    if (!$isAdmin && $order['status'] !== 'submitted') {
        throw new Exception("لا يمكن لمقدم الطلب حذف الطلب بعد بدء التوجيه");
    }

    $directionCheck = $pdo->prepare(" 
        SELECT COUNT(*)
        FROM requirement_directions rd
        INNER JOIN requirements r ON r.id = rd.requirement_id
        WHERE r.order_id = ?
    ");

    $directionCheck->execute([$orderId]);
    $hasDirections = (int) $directionCheck->fetchColumn() > 0;

    if (!$isAdmin && $hasDirections) {
        throw new Exception("لا يمكن حذف طلب تم توجيه أحد مطاليبه");
    }

    /*
     * حذف البيانات التابعة بالترتيب الصحيح حتى لا تظهر مشكلة مفاتيح أجنبية.
     */
    $deleteApprovals = $pdo->prepare(" 
        DELETE FROM receipt_approvals
        WHERE requirement_id IN (
            SELECT id FROM requirements WHERE order_id = ?
        )
    ");
    $deleteApprovals->execute([$orderId]);

    $deleteReceipts = $pdo->prepare(" 
        DELETE FROM execution_receipts
        WHERE requirement_id IN (
            SELECT id FROM requirements WHERE order_id = ?
        )
    ");
    $deleteReceipts->execute([$orderId]);

    $deleteActions = $pdo->prepare(" 
        DELETE FROM requirement_actions
        WHERE requirement_id IN (
            SELECT id FROM requirements WHERE order_id = ?
        )
    ");
    $deleteActions->execute([$orderId]);

    $deleteDirections = $pdo->prepare(" 
        DELETE FROM requirement_directions
        WHERE requirement_id IN (
            SELECT id FROM requirements WHERE order_id = ?
        )
    ");
    $deleteDirections->execute([$orderId]);

    $deleteRequirements = $pdo->prepare(" 
        DELETE FROM requirements
        WHERE order_id = ?
    ");
    $deleteRequirements->execute([$orderId]);

    $deleteOrder = $pdo->prepare(" 
        DELETE FROM orders
        WHERE id = ?
    ");
    $deleteOrder->execute([$orderId]);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم حذف الطلب بنجاح"
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
