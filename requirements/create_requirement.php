<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';

// صلاحية إنشاء مطلوب
requirePermission('create_requirement');

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المرسلة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$orderId = (int) ($data['order_id'] ?? 0);
$requirementText = trim((string) ($data['requirement'] ?? $data['title'] ?? ''));
$problem = trim((string) ($data['problem'] ?? ''));

if ($orderId <= 0 || $requirementText === '' || $problem === '') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم الطلب ونص المطلوب والمشكلة مطلوبة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

    /*
     * لا يسمح بإضافة مطلوب إلا لصاحب الطلب أو مدير النظام.
     * مقدم الطلب يضيف المطاليب قبل بدء التوجيه فقط.
     */
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
        throw new Exception("غير مسموح لك بإضافة مطلوب لهذا الطلب");
    }

    if (!$isAdmin && $order['status'] !== 'submitted') {
        throw new Exception("لا يمكن إضافة مطلوب بعد بدء توجيه الطلب");
    }

    if ($order['status'] === 'completed' || $order['status'] === 'cancelled') {
        throw new Exception("لا يمكن إضافة مطلوب إلى طلب مكتمل أو ملغي");
    }

    $insert = $pdo->prepare(" 
        INSERT INTO requirements (
            order_id,
            requirement,
            problem,
            status,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, 'new', NOW(), NOW())
        RETURNING id
    ");

    $insert->execute([
        $orderId,
        $requirementText,
        $problem
    ]);

    $requirementId = (int) $insert->fetchColumn();

    $orderStatus = updateOrderStatus($pdo, $orderId);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم إنشاء المطلوب بنجاح",
        "requirement_id" => $requirementId,
        "order_status" => $orderStatus
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
