<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('cancel_order');

$data = json_decode(file_get_contents("php://input"), true);

$order_id = $data['order_id'] ?? null;

if (empty($order_id)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم الطلب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$stmt = $pdo->prepare("
    SELECT id, requester_id, status
    FROM orders
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);

    echo json_encode([
        "status" => false,
        "message" => "الطلب غير موجود"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
يسمح فقط:
- مدير النظام
- مقدم الطلب صاحب الطلب
*/
if (!authUserHasRole('admin') && $order['requester_id'] != $authUser['id']) {
    http_response_code(403);

    echo json_encode([
        "status" => false,
        "message" => "غير مسموح لك بإلغاء هذا الطلب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
مقدم الطلب يلغي فقط إذا submitted
مدير النظام يلغي في أي وقت
*/
if (!authUserHasRole('admin') && $order['status'] !== 'submitted') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "لا يمكن لمقدم الطلب إلغاء الطلب بعد التوجيه"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
إلغاء الطلب + إلغاء كل المطاليب التابعة له
*/
try {
    $pdo->beginTransaction();

    $updateOrder = $pdo->prepare("
        UPDATE orders
        SET status = 'cancelled',
            updated_at = NOW()
        WHERE id = ?
    ");

    $updateOrder->execute([$order_id]);

    $updateRequirements = $pdo->prepare("
        UPDATE requirements
        SET status = 'cancelled',
            updated_at = NOW()
        WHERE order_id = ?
    ");

    $updateRequirements->execute([$order_id]);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم إلغاء الطلب ح"
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $pdo->rollBack();

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل إلغاء الطلب",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}