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
$name = trim($data['name'] ?? '');
$isActive = $data['is_active'] ?? null;

if ($requestTypeId <= 0) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'رقم نوع الطلب مطلوب'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($name === '') {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'اسم نوع الطلب مطلوب'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($name, 'UTF-8') > 150) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'اسم نوع الطلب يجب ألا يتجاوز 150 حرفًا'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array((string) $isActive, ['0', '1'], true)) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'قيمة حالة التفعيل غير صحيحة'
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

    // منع استخدام اسم موجود لنوع طلب آخر.
    $duplicateStatement = $pdo->prepare("\n        SELECT id\n        FROM request_types\n        WHERE name = ? AND id <> ?\n        LIMIT 1\n    ");

    $duplicateStatement->execute([
        $name,
        $requestTypeId
    ]);

    if ($duplicateStatement->fetch()) {
        http_response_code(409);

        echo json_encode([
            'status' => false,
            'message' => 'اسم نوع الطلب موجود مسبقًا'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تحديث بيانات نوع الطلب.
    $statement = $pdo->prepare("\n        UPDATE request_types\n        SET\n            name = ?,\n            is_active = ?\n        WHERE id = ?\n    ");

    $statement->execute([
        $name,
        (int) $isActive,
        $requestTypeId
    ]);

    echo json_encode([
        'status' => true,
        'message' => 'تم تعديل نوع الطلب بنجاح',
        'data' => [
            'id' => $requestTypeId,
            'name' => $name,
            'is_active' => (int) $isActive
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء تعديل نوع الطلب'
    ], JSON_UNESCAPED_UNICODE);
}
