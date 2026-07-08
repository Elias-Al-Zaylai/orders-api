<?php

// تحديد نوع الاستجابة بصيغة JSON وترميز UTF-8.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول ومن صلاحية إدارة المستخدمين.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

// هذا الملف يقبل طلبات POST فقط.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'status' => false,
        'message' => 'طريقة الطلب غير مسموحة'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'بيانات الطلب غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($data['id'] ?? $data['user_id'] ?? 0);
$roleIds = $data['role_ids'] ?? [];
$roleIds = is_array($roleIds)
    ? array_values(array_unique(array_filter(
        array_map('intval', $roleIds),
        static fn(int $id): bool => $id > 0
    )))
    : [];

if ($userId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'رقم المستخدم مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($roleIds)) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'يجب اختيار دور واحد على الأقل'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $userStatement = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $userStatement->execute([$userId]);

    if (!$userStatement->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'المستخدم غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // التأكد من أن جميع الأدوار المرسلة موجودة ومفعلة.
    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
    $rolesStatement = $pdo->prepare("
        SELECT id, name, display_name
        FROM roles
        WHERE id IN ($placeholders)
          AND is_active = TRUE
    ");
    $rolesStatement->execute($roleIds);

    $rolesRows = $rolesStatement->fetchAll(PDO::FETCH_ASSOC);
    $rolesById = [];

    foreach ($rolesRows as $role) {
        $rolesById[(int) $role['id']] = $role;
    }

    if (count($rolesById) !== count($roleIds)) {
        http_response_code(422);
        echo json_encode(['status' => false, 'message' => 'أحد الأدوار المختارة غير موجود أو غير مفعل'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تحديث الحقلين القديمين في users بأول دور حتى يبقيا متوافقين مع user_roles.
    $primaryRoleId = $roleIds[0];
    $primaryRoleName = (string) $rolesById[$primaryRoleId]['name'];
    $allowedLegacyRoles = ['admin', 'requester', 'direction_manager', 'executor'];

    if (!in_array($primaryRoleName, $allowedLegacyRoles, true)) {
        http_response_code(422);
        echo json_encode([
            'status' => false,
            'message' => 'اسم الدور الأساسي غير متوافق مع حقل role الموجود في جدول users'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    // حذف الروابط القديمة ثم إنشاء الروابط الجديدة داخل عملية واحدة.
    $deleteStatement = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
    $deleteStatement->execute([$userId]);

    $insertStatement = $pdo->prepare("
        INSERT INTO user_roles (user_id, role_id, created_at)
        VALUES (?, ?, NOW())
    ");

    foreach ($roleIds as $roleId) {
        $insertStatement->execute([$userId, $roleId]);
    }

    $updateUserStatement = $pdo->prepare("
        UPDATE users
        SET role = ?, role_id = ?
        WHERE id = ?
    ");
    $updateUserStatement->execute([
        $primaryRoleName,
        $primaryRoleId,
        $userId
    ]);

    $pdo->commit();

    $responseRoles = [];
    foreach ($roleIds as $roleId) {
        $responseRoles[] = [
            'id' => $roleId,
            'name' => $rolesById[$roleId]['name'],
            'display_name' => $rolesById[$roleId]['display_name']
        ];
    }

    echo json_encode([
        'status' => true,
        'message' => 'تم تحديث أدوار المستخدم بنجاح',
        'data' => [
            'user_id' => $userId,
            'roles' => $responseRoles
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء تحديث أدوار المستخدم',
        'error' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
