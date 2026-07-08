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

$roleId = (int) ($data['role_id'] ?? 0);
$permissionIds = $data['permission_ids'] ?? null;

if ($roleId <= 0 || !is_array($permissionIds)) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'رقم الدور وقائمة الصلاحيات مطلوبان',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تنظيف القائمة وحذف الأرقام المكررة وغير الصحيحة.
$permissionIds = array_values(array_unique(array_filter(
    array_map('intval', $permissionIds),
    static fn(int $id): bool => $id > 0
)));

try {
    $pdo->beginTransaction();

    // التأكد من وجود الدور وقفل سجله أثناء عملية التحديث.
    $roleStatement = $pdo->prepare("\n        SELECT id\n        FROM roles\n        WHERE id = ?\n        LIMIT 1\n        FOR UPDATE\n    ");
    $roleStatement->execute([$roleId]);

    if (!$roleStatement->fetch()) {
        $pdo->rollBack();
        http_response_code(404);

        echo json_encode([
            'status' => false,
            'message' => 'الدور غير موجود',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // التأكد من أن جميع الصلاحيات المرسلة موجودة في الجدول.
    if (!empty($permissionIds)) {
        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
        $permissionStatement = $pdo->prepare("\n            SELECT id\n            FROM permissions\n            WHERE id IN ($placeholders)\n        ");
        $permissionStatement->execute($permissionIds);

        $existingIds = array_map(
            'intval',
            $permissionStatement->fetchAll(PDO::FETCH_COLUMN)
        );

        sort($existingIds);
        $submittedIds = $permissionIds;
        sort($submittedIds);

        if ($existingIds !== $submittedIds) {
            $pdo->rollBack();
            http_response_code(422);

            echo json_encode([
                'status' => false,
                'message' => 'توجد صلاحية غير موجودة ضمن القائمة المرسلة',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // حذف الربط السابق بالكامل ثم إضافة الربط الجديد.
    $deleteStatement = $pdo->prepare("\n        DELETE FROM role_permissions\n        WHERE role_id = ?\n    ");
    $deleteStatement->execute([$roleId]);

    if (!empty($permissionIds)) {
        $insertStatement = $pdo->prepare("\n            INSERT INTO role_permissions (role_id, permission_id)\n            VALUES (?, ?)\n        ");

        foreach ($permissionIds as $permissionId) {
            $insertStatement->execute([$roleId, $permissionId]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'status' => true,
        'message' => 'تم تحديث صلاحيات الدور بنجاح',
        'data' => [
            'role_id' => $roleId,
            'permission_ids' => $permissionIds,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء تحديث صلاحيات الدور',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
