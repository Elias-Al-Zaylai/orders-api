<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('delete_order');

function isAdmin($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT ur.id
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
        AND r.name = 'admin'
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    return $stmt->fetch() ? true : false;
}

$isAdmin = isAdmin($pdo, $authUser['id']);

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

if (!$isAdmin && $order['requester_id'] != $authUser['id']) {
    http_response_code(403);

    echo json_encode([
        "status" => false,
        "message" => "غير مسموح لك بحذف هذا الطلب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (!$isAdmin && $order['status'] !== 'submitted') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "لا يمكن لمقدم الطلب حذف الطلب بعد التوجيه"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$check = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM requirement_directions rd
    INNER JOIN requirements r ON r.id = rd.requirement_id
    WHERE r.order_id = ?
");

$check->execute([$order_id]);
$hasDirections = $check->fetch();

if (!$isAdmin && $hasDirections['total'] > 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "لا يمكن حذف طلب تم توجيه أحد مطاليبه"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

    $deleteReq = $pdo->prepare("
        DELETE FROM requirements
        WHERE order_id = ?
    ");

    $deleteReq->execute([$order_id]);

    $deleteOrder = $pdo->prepare("
        DELETE FROM orders
        WHERE id = ?
    ");

    $deleteOrder->execute([$order_id]);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم حذف الطلب بنجاح"
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $pdo->rollBack();

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل حذف الطلب، ربما توجد بيانات مرتبطة به",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}