<?php

// تحديد نوع الاستجابة بصيغة JSON ودعم اللغة العربية.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول والصلاحيات.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);

    echo json_encode([
        'status' => false,
        'message' => 'طريقة الطلب غير مسموحة',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$roleId = (int) ($data['role_id'] ?? $data['id'] ?? 0);

if ($roleId <= 0) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'رقم الدور مطلوب',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    // جلب الحالة الحالية وقفل السجل حتى انتهاء التعديل.
    $roleStatement = $pdo->prepare("\n        SELECT id, is_active\n        FROM roles\n        WHERE id = ?\n        LIMIT 1\n        FOR UPDATE\n    ");
    $roleStatement->execute([$roleId]);
    $role = $roleStatement->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        $pdo->rollBack();
        http_response_code(404);

        echo json_encode([
            'status' => false,
            'message' => 'الدور غير موجود',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newStatus = ((int) $role['is_active'] === 1) ? 0 : 1;

    $updateStatement = $pdo->prepare("\n        UPDATE roles\n        SET is_active = ?\n        WHERE id = ?\n    ");
    $updateStatement->execute([$newStatus, $roleId]);

    $pdo->commit();

    echo json_encode([
        'status' => true,
        'message' => $newStatus === 1
            ? 'تم تفعيل الدور بنجاح'
            : 'تم إيقاف الدور بنجاح',
        'data' => [
            'id' => $roleId,
            'is_active' => (bool) $newStatus,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء تغيير حالة الدور',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
