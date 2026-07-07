<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('update_requirement');

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

if (!authUserHasRole('admin') && $item['requester_id'] != $authUser['id']) {
    http_response_code(403);

    echo json_encode([
        "status" => false,
        "message" => "غير مسموح لك بإلغاء هذا المطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (!authUserHasRole('admin') && $item['status'] !== 'new') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "لا يمكن لمقدم الطلب إلغاء المطلوب بعد التوجيه"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

    $updateRequirement = $pdo->prepare("
        UPDATE requirements
        SET status = 'cancelled',
            updated_at = NOW()
        WHERE id = ?
    ");

    $updateRequirement->execute([$requirement_id]);

    $countNotCancelled = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM requirements
        WHERE order_id = ?
        AND status != 'cancelled'
    ");

    $countNotCancelled->execute([$item['order_id']]);
    $open = $countNotCancelled->fetch();

    if ($open['total'] == 0) {
        $updateOrder = $pdo->prepare("
            UPDATE orders
            SET status = 'cancelled',
                updated_at = NOW()
            WHERE id = ?
        ");

        $updateOrder->execute([$item['order_id']]);
    }

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم إلغاء المطلوب بنجاح"
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $pdo->rollBack();

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل إلغاء المطلوب",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}