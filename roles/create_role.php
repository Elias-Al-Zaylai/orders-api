<?php

// تحديد نوع الاستجابة بصيغة JSON ودعم اللغة العربية.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول والصلاحيات.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$name = strtolower(trim((string) ($data['name'] ?? '')));
$displayName = trim((string) ($data['display_name'] ?? ''));
$isActive = isset($data['is_active']) ? (int) ((bool) $data['is_active']) : 1;
$permissionIds = $data['permission_ids'] ?? [];

if ($name === '' || $displayName === '') {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'اسم الدور والاسم الظاهر مطلوبان',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// اسم الدور البرمجي يقبل أحرفًا إنجليزية وأرقامًا وشرطة سفلية فقط.
if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'اسم الدور يجب أن يبدأ بحرف إنجليزي ويحتوي على أحرف إنجليزية وأرقام وشرطة سفلية فقط',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($permissionIds)) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'قائمة الصلاحيات غير صالحة',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تنظيف أرقام الصلاحيات وحذف القيم المكررة وغير الصحيحة.
$permissionIds = array_values(array_unique(array_filter(
    array_map('intval', $permissionIds),
    static fn(int $id): bool => $id > 0
)));

try {
    $pdo->beginTransaction();

    // التأكد من عدم تكرار اسم الدور.
    $duplicateStatement = $pdo->prepare("\n        SELECT id\n        FROM roles\n        WHERE name = ?\n        LIMIT 1\n    ");
    $duplicateStatement->execute([$name]);

    if ($duplicateStatement->fetch()) {
        $pdo->rollBack();
        http_response_code(409);

        echo json_encode([
            'status' => false,
            'message' => 'اسم الدور مستخدم مسبقًا',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // التحقق من أن جميع أرقام الصلاحيات موجودة فعلًا.
    if (!empty($permissionIds)) {
        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
        $permissionsCheck = $pdo->prepare("\n            SELECT id\n            FROM permissions\n            WHERE id IN ($placeholders)\n        ");
        $permissionsCheck->execute($permissionIds);

        $existingPermissionIds = array_map(
            'intval',
            $permissionsCheck->fetchAll(PDO::FETCH_COLUMN)
        );

        sort($existingPermissionIds);
        $submittedPermissionIds = $permissionIds;
        sort($submittedPermissionIds);

        if ($existingPermissionIds !== $submittedPermissionIds) {
            $pdo->rollBack();
            http_response_code(422);

            echo json_encode([
                'status' => false,
                'message' => 'توجد صلاحية غير موجودة ضمن القائمة المرسلة',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // إنشاء الدور.
    $insertRole = $pdo->prepare("\n        INSERT INTO roles (name, display_name, is_active)\n        VALUES (?, ?, ?)\n        RETURNING id\n    ");
    $insertRole->execute([$name, $displayName, $isActive]);

    $roleId = (int) $insertRole->fetchColumn();

    // ربط الصلاحيات بالدور عند إرسالها مع الطلب.
    if (!empty($permissionIds)) {
        $insertPermission = $pdo->prepare("\n            INSERT INTO role_permissions (role_id, permission_id)\n            VALUES (?, ?)\n        ");

        foreach ($permissionIds as $permissionId) {
            $insertPermission->execute([$roleId, $permissionId]);
        }
    }

    $pdo->commit();
    http_response_code(201);

    echo json_encode([
        'status' => true,
        'message' => 'تم إنشاء الدور بنجاح',
        'data' => [
            'id' => $roleId,
            'name' => $name,
            'display_name' => $displayName,
            'is_active' => (bool) $isActive,
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
        'message' => 'حدث خطأ أثناء إنشاء الدور',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
