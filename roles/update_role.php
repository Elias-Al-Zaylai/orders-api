<?php

// تحديد نوع الاستجابة بصيغة JSON ودعم اللغة العربية.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول والصلاحيات.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);

    echo json_encode([
        'status' => false,
        'message' => 'طريقة الطلب غير مسموحة',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        'status' => false,
        'message' => 'بيانات الطلب غير صالحة',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$roleId = (int) ($data['role_id'] ?? $data['id'] ?? 0);
$name = strtolower(trim((string) ($data['name'] ?? '')));
$displayName = trim((string) ($data['display_name'] ?? ''));
$isActive = isset($data['is_active']) ? (int) ((bool) $data['is_active']) : 1;

if ($roleId <= 0 || $name === '' || $displayName === '') {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'رقم الدور واسم الدور والاسم الظاهر مطلوبة',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'اسم الدور يجب أن يبدأ بحرف إنجليزي ويحتوي على أحرف إنجليزية وأرقام وشرطة سفلية فقط',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // التأكد من وجود الدور.
    $roleStatement = $pdo->prepare("\n        SELECT id\n        FROM roles\n        WHERE id = ?\n        LIMIT 1\n    ");
    $roleStatement->execute([$roleId]);

    if (!$roleStatement->fetch()) {
        http_response_code(404);

        echo json_encode([
            'status' => false,
            'message' => 'الدور غير موجود',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // التأكد من عدم استخدام الاسم من دور آخر.
    $duplicateStatement = $pdo->prepare("\n        SELECT id\n        FROM roles\n        WHERE name = ?\n          AND id <> ?\n        LIMIT 1\n    ");
    $duplicateStatement->execute([$name, $roleId]);

    if ($duplicateStatement->fetch()) {
        http_response_code(409);

        echo json_encode([
            'status' => false,
            'message' => 'اسم الدور مستخدم مسبقًا',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updateStatement = $pdo->prepare("\n        UPDATE roles\n        SET\n            name = ?,\n            display_name = ?,\n            is_active = ?\n        WHERE id = ?\n    ");
    $updateStatement->execute([
        $name,
        $displayName,
        $isActive,
        $roleId,
    ]);

    echo json_encode([
        'status' => true,
        'message' => 'تم تعديل الدور بنجاح',
        'data' => [
            'id' => $roleId,
            'name' => $name,
            'display_name' => $displayName,
            'is_active' => (bool) $isActive,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء تعديل الدور',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
