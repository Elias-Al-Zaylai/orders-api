<?php

// تحديد نوع الاستجابة JSON مع دعم اللغة العربية.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول والصلاحية.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_settings');

// قراءة بيانات JSON المرسلة من التطبيق.
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    $data = [];
}

$requestTypeId = (int) ($data['id'] ?? 0);

if ($requestTypeId <= 0) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'رقم نوع الطلب مطلوب'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // التأكد من وجود نوع الطلب.
    $findStatement = $pdo->prepare("\n        SELECT id\n        FROM request_types\n        WHERE id = ?\n        LIMIT 1\n    ");

    $findStatement->execute([$requestTypeId]);

    if (!$findStatement->fetch()) {
        http_response_code(404);

        echo json_encode([
            'status' => false,
            'message' => 'نوع الطلب غير موجود'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // منع حذف النوع إذا كان مستخدمًا داخل الطلبات.
    $ordersStatement = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM orders\n        WHERE request_type_id = ?\n    ");

    $ordersStatement->execute([$requestTypeId]);
    $ordersCount = (int) $ordersStatement->fetchColumn();

    if ($ordersCount > 0) {
        http_response_code(409);

        echo json_encode([
            'status' => false,
            'message' => 'لا يمكن حذف نوع الطلب لأنه مستخدم في طلبات موجودة'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف نوع الطلب.
    $statement = $pdo->prepare("\n        DELETE FROM request_types\n        WHERE id = ?\n    ");

    $statement->execute([$requestTypeId]);

    echo json_encode([
        'status' => true,
        'message' => 'تم حذف نوع الطلب بنجاح'
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء حذف نوع الطلب'
    ], JSON_UNESCAPED_UNICODE);
}
