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
    // جلب الحالة الحالية.
    $findStatement = $pdo->prepare("\n        SELECT id, is_active\n        FROM request_types\n        WHERE id = ?\n        LIMIT 1\n    ");

    $findStatement->execute([$requestTypeId]);
    $requestType = $findStatement->fetch(PDO::FETCH_ASSOC);

    if (!$requestType) {
        http_response_code(404);

        echo json_encode([
            'status' => false,
            'message' => 'نوع الطلب غير موجود'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // عكس الحالة الحالية: النشط يصبح موقوفًا والعكس.
    $newStatus = ((int) $requestType['is_active'] === 1) ? 0 : 1;

    $statement = $pdo->prepare("\n        UPDATE request_types\n        SET is_active = ?\n        WHERE id = ?\n    ");

    $statement->execute([
        $newStatus,
        $requestTypeId
    ]);

    echo json_encode([
        'status' => true,
        'message' => $newStatus === 1
            ? 'تم تفعيل نوع الطلب بنجاح'
            : 'تم إيقاف نوع الطلب بنجاح',
        'data' => [
            'id' => $requestTypeId,
            'is_active' => $newStatus
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء تغيير حالة نوع الطلب'
    ], JSON_UNESCAPED_UNICODE);
}
