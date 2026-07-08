<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية إلغاء الطلب
requirePermission('cancel_order');

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
        throw new Exception("غير مسموح لك بإلغاء هذا الطلب");
    }

    if ($order['status'] === 'cancelled') {
        throw new Exception("الطلب ملغي مسبقًا");
    }

    if ($order['status'] === 'completed') {
        throw new Exception("لا يمكن إلغاء طلب مكتمل");
    }

    // مقدم الطلب يلغي فقط قبل بدء التوجيه
    if (!$isAdmin && $order['status'] !== 'submitted') {
        throw new Exception("لا يمكن لمقدم الطلب إلغاء الطلب بعد بدء التوجيه");
    }

    $updateOrder = $pdo->prepare(" 
        UPDATE orders
        SET status = 'cancelled',
            updated_at = NOW()
        WHERE id = ?
    ");

    $updateOrder->execute([$orderId]);

    $updateRequirements = $pdo->prepare(" 
        UPDATE requirements
        SET status = 'cancelled',
            updated_at = NOW()
        WHERE order_id = ?
          AND status <> 'closed'
    ");

    $updateRequirements->execute([$orderId]);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم إلغاء الطلب بنجاح",
        "order_status" => "cancelled"
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
