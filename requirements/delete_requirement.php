<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';

requirePermission('delete_requirement');

$data = json_decode(file_get_contents("php://input"), true);

$requirement_id = $data['requirement_id'] ?? null;

if (empty($requirement_id)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.order_id,
        r.status,
        o.requester_id
    FROM requirements r
    INNER JOIN orders o ON o.id = r.order_id
    WHERE r.id = ?
    LIMIT 1
");

$stmt->execute([$requirement_id]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);

    echo json_encode([
        "status" => false,
        "message" => "المطلوب غير موجود"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
مدير النظام أو مقدم الطلب صاحب الطلب
*/
if (!authUserHasRole('admin') && $item['requester_id'] != $authUser['id']) {
    http_response_code(403);

    echo json_encode([
        "status" => false,
        "message" => "غير مسموح لك بحذف هذا المطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
مقدم الطلب يحذف فقط إذا المطلوب new
*/
if (!authUserHasRole('admin') && $item['status'] !== 'new') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "لا يمكن حذف المطلوب بعد التوجيه"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {

    $pdo->beginTransaction();

    $checkDirections = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM requirement_directions
        WHERE requirement_id = ?
    ");

    $checkDirections->execute([$requirement_id]);

    $directions = $checkDirections->fetch();

    if (!authUserHasRole('admin') && $directions['total'] > 0) {

        throw new Exception("لا يمكن حذف مطلوب تم توجيهه");

    }

    $delete = $pdo->prepare("
        DELETE FROM requirements
        WHERE id = ?
    ");

    $delete->execute([$requirement_id]);

    // تحديث حالة الطلب تلقائيًا بعد حذف المطلوب
    $newOrderStatus = updateOrderStatus($pdo, (int) $item['order_id']);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم حذف المطلوب بنجاح",
        "order_status" => $newOrderStatus
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {

    $pdo->rollBack();

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}