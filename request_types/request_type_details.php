<?php

// تحديد نوع الاستجابة JSON مع دعم اللغة العربية.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول والصلاحية.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_settings');

// رقم نوع الطلب يرسل في الرابط مثل: ?id=1
$requestTypeId = (int) ($_GET['id'] ?? 0);

if ($requestTypeId <= 0) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'رقم نوع الطلب مطلوب'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // جلب نوع طلب واحد حسب الرقم.
    $statement = $pdo->prepare("\n        SELECT\n            id,\n            name,\n            is_active,\n            created_at,\n            updated_at\n        FROM request_types\n        WHERE id = ?\n        LIMIT 1\n    ");

    $statement->execute([$requestTypeId]);
    $requestType = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$requestType) {
        http_response_code(404);

        echo json_encode([
            'status' => false,
            'message' => 'نوع الطلب غير موجود'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $requestType['id'] = (int) $requestType['id'];
    $requestType['is_active'] = (int) $requestType['is_active'];

    echo json_encode([
        'status' => true,
        'message' => 'تم جلب تفاصيل نوع الطلب بنجاح',
        'data' => $requestType
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء جلب تفاصيل نوع الطلب'
    ], JSON_UNESCAPED_UNICODE);
}
