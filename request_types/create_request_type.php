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

$name = trim($data['name'] ?? '');
$isActive = $data['is_active'] ?? 1;

// التحقق من اسم نوع الطلب.
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

// التحقق من حالة التفعيل.
if (!in_array((string) $isActive, ['0', '1'], true)) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'قيمة حالة التفعيل غير صحيحة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // منع إضافة اسم مكرر.
    $checkStatement = $pdo->prepare("\n        SELECT id\n        FROM request_types\n        WHERE name = ?\n        LIMIT 1\n    ");

    $checkStatement->execute([$name]);

    if ($checkStatement->fetch()) {
        http_response_code(409);

        echo json_encode([
            'status' => false,
            'message' => 'اسم نوع الطلب موجود مسبقًا'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // إضافة نوع الطلب الجديد.
    $statement = $pdo->prepare("\n        INSERT INTO request_types (name, is_active)\n        VALUES (?, ?)\n        RETURNING id\n    ");

    $statement->execute([
        $name,
        (int) $isActive
    ]);

    $requestTypeId = (int) $statement->fetchColumn();

    echo json_encode([
        'status' => true,
        'message' => 'تم إضافة نوع الطلب بنجاح',
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
        'message' => 'حدث خطأ أثناء إضافة نوع الطلب'
    ], JSON_UNESCAPED_UNICODE);
}
