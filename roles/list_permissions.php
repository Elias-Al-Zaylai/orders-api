<?php

// تحديد نوع الاستجابة بصيغة JSON ودعم اللغة العربية.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول والصلاحيات.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

try {
    // جلب جميع الصلاحيات مرتبة حسب المجموعة ثم الاسم الظاهر.
    $statement = $pdo->prepare("\n        SELECT\n            id,\n            permission_key,\n            display_name,\n            group_name,\n            created_at,\n            updated_at\n        FROM permissions\n        ORDER BY\n            COALESCE(group_name, 'أخرى'),\n            display_name\n    ");

    $statement->execute();
    $permissions = $statement->fetchAll(PDO::FETCH_ASSOC);

    foreach ($permissions as &$permission) {
        $permission['id'] = (int) $permission['id'];
        $permission['group_name'] = $permission['group_name'] ?: 'أخرى';
    }
    unset($permission);

    // تجميع الصلاحيات حسب group_name لتسهيل عرضها في Flutter.
    $groups = [];

    foreach ($permissions as $permission) {
        $groupName = $permission['group_name'];

        if (!isset($groups[$groupName])) {
            $groups[$groupName] = [];
        }

        $groups[$groupName][] = $permission;
    }

    $groupedPermissions = [];

    foreach ($groups as $groupName => $items) {
        $groupedPermissions[] = [
            'group_name' => $groupName,
            'permissions' => $items,
        ];
    }

    echo json_encode([
        'status' => true,
        'message' => 'تم جلب الصلاحيات بنجاح',
        'data' => $permissions,
        'groups' => $groupedPermissions,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء جلب الصلاحيات',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
