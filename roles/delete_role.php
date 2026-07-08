<?php

// تحديد نوع الاستجابة بصيغة JSON ودعم اللغة العربية.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول والصلاحيات.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

    // التأكد من وجود الدور وقفل السجل أثناء الحذف.
    $roleStatement = $pdo->prepare("\n        SELECT id, name, display_name\n        FROM roles\n        WHERE id = ?\n        LIMIT 1\n        FOR UPDATE\n    ");
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

    // منع حذف الدور إذا كان مرتبطًا بأي مستخدم.
    $usersStatement = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM user_roles\n        WHERE role_id = ?\n    ");
    $usersStatement->execute([$roleId]);
    $usersCount = (int) $usersStatement->fetchColumn();

    if ($usersCount > 0) {
        $pdo->rollBack();
        http_response_code(409);

        echo json_encode([
            'status' => false,
            'message' => 'لا يمكن حذف الدور لأنه مرتبط بمستخدمين. قم بإزالة الدور من المستخدمين أولًا',
            'users_count' => $usersCount,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف روابط الصلاحيات أولًا لضمان نجاح الحذف حتى بدون ON DELETE CASCADE.
    $deletePermissions = $pdo->prepare("\n        DELETE FROM role_permissions\n        WHERE role_id = ?\n    ");
    $deletePermissions->execute([$roleId]);

    // حذف الدور نفسه.
    $deleteRole = $pdo->prepare("\n        DELETE FROM roles\n        WHERE id = ?\n    ");
    $deleteRole->execute([$roleId]);

    $pdo->commit();

    echo json_encode([
        'status' => true,
        'message' => 'تم حذف الدور بنجاح',
        'data' => [
            'id' => $roleId,
            'name' => $role['name'],
            'display_name' => $role['display_name'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء حذف الدور',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
