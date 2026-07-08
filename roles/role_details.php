<?php

// تحديد نوع الاستجابة بصيغة JSON ودعم اللغة العربية.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول والصلاحيات.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

// استقبال رقم الدور من GET أو POST JSON.
$input = json_decode(file_get_contents('php://input'), true);
$roleId = (int) ($_GET['role_id'] ?? $input['role_id'] ?? 0);

if ($roleId <= 0) {
    http_response_code(400);

    echo json_encode([
        'status' => false,
        'message' => 'رقم الدور مطلوب',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // جلب بيانات الدور الأساسية.
    $roleStatement = $pdo->prepare("\n        SELECT\n            id,\n            name,\n            display_name,\n            is_active,\n            created_at,\n            updated_at\n        FROM roles\n        WHERE id = ?\n        LIMIT 1\n    ");

    $roleStatement->execute([$roleId]);
    $role = $roleStatement->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        http_response_code(404);

        echo json_encode([
            'status' => false,
            'message' => 'الدور غير موجود',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // جلب الصلاحيات المرتبطة بالدور.
    $permissionsStatement = $pdo->prepare("\n        SELECT\n            p.id,\n            p.permission_key,\n            p.display_name,\n            p.group_name\n        FROM permissions p\n        INNER JOIN role_permissions rp\n            ON rp.permission_id = p.id\n        WHERE rp.role_id = ?\n        ORDER BY\n            COALESCE(p.group_name, ''),\n            p.display_name\n    ");

    $permissionsStatement->execute([$roleId]);
    $permissions = $permissionsStatement->fetchAll(PDO::FETCH_ASSOC);

    $role['id'] = (int) $role['id'];
    $role['is_active'] = (bool) $role['is_active'];

    foreach ($permissions as &$permission) {
        $permission['id'] = (int) $permission['id'];
    }
    unset($permission);

    $role['permissions'] = $permissions;
    $role['permission_ids'] = array_map(
        static fn(array $permission): int => (int) $permission['id'],
        $permissions
    );

    echo json_encode([
        'status' => true,
        'message' => 'تم جلب تفاصيل الدور بنجاح',
        'data' => $role,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء جلب تفاصيل الدور',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
