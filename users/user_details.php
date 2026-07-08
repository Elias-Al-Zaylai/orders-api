<?php

// تحديد نوع الاستجابة بصيغة JSON وترميز UTF-8.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول وصلاحية إدارة المستخدمين.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

// السماح بطلب GET فقط.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'status' => false,
        'message' => 'طريقة الطلب غير مسموحة'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// قبول id أو user_id لسهولة الربط مع Flutter.
$userId = (int) ($_GET['user_id'] ?? $_GET['id'] ?? 0);

if ($userId <= 0) {
    http_response_code(400);

    echo json_encode([
        'status' => false,
        'message' => 'رقم المستخدم مطلوب'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    // جلب البيانات الأساسية للمستخدم مع أسماء الشركة والإدارة والقسم.
    $userStatement = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.username,
            u.phone,
            u.email,
            u.company_id,
            u.department_id,
            u.section_id,
            u.is_active,
            u.last_login,
            u.created_at,
            u.updated_at,
            c.name AS company_name,
            d.name AS department_name,
            s.name AS section_name
        FROM users u
        LEFT JOIN companies c
            ON c.id = u.company_id
        LEFT JOIN departments d
            ON d.id = u.department_id
        LEFT JOIN sections s
            ON s.id = u.section_id
        WHERE u.id = ?
        LIMIT 1
    ");

    $userStatement->execute([$userId]);
    $user = $userStatement->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);

        echo json_encode([
            'status' => false,
            'message' => 'المستخدم غير موجود'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // جلب الأدوار المرتبطة بالمستخدم من الجدول الوسيط user_roles.
    $rolesStatement = $pdo->prepare("
        SELECT
            r.id,
            r.name,
            r.display_name,
            r.is_active,
            r.created_at,
            r.updated_at
        FROM user_roles ur
        INNER JOIN roles r
            ON r.id = ur.role_id
        WHERE ur.user_id = ?
        ORDER BY r.display_name ASC, r.id ASC
    ");

    $rolesStatement->execute([$userId]);
    $roles = $rolesStatement->fetchAll(PDO::FETCH_ASSOC);

    foreach ($roles as &$role) {
        $role['id'] = (int) $role['id'];
        $role['is_active'] = (bool) $role['is_active'];
    }
    unset($role);

    /*
     * الصلاحيات معلومات إضافية وليست شرطًا لفتح تفاصيل المستخدم.
     * لذلك إذا كان جدول الصلاحيات مختلفًا أو غير مكتمل، نعيد قائمة فارغة
     * بدل فشل شاشة التفاصيل كاملة.
     */
    $permissions = [];

    try {
        $permissionsStatement = $pdo->prepare("
            SELECT DISTINCT
                p.id,
                p.permission_key,
                p.display_name,
                p.group_name
            FROM user_roles ur
            INNER JOIN role_permissions rp
                ON rp.role_id = ur.role_id
            INNER JOIN permissions p
                ON p.id = rp.permission_id
            WHERE ur.user_id = ?
            ORDER BY p.group_name ASC, p.display_name ASC
        ");

        $permissionsStatement->execute([$userId]);
        $permissions = $permissionsStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($permissions as &$permission) {
            $permission['id'] = (int) $permission['id'];
        }
        unset($permission);
    } catch (PDOException $permissionException) {
        // تجاهل خطأ الصلاحيات فقط حتى تبقى بيانات المستخدم والأدوار متاحة.
        $permissions = [];
    }

    // تحويل الأنواع قبل إرسال JSON.
    $user['id'] = (int) $user['id'];
    $user['company_id'] = $user['company_id'] !== null
        ? (int) $user['company_id']
        : null;
    $user['department_id'] = $user['department_id'] !== null
        ? (int) $user['department_id']
        : null;
    $user['section_id'] = $user['section_id'] !== null
        ? (int) $user['section_id']
        : null;
    $user['is_active'] = (bool) $user['is_active'];

    // إضافة الأدوار والصلاحيات داخل بيانات المستخدم لتناسب موديل Flutter.
    $user['roles'] = $roles;
    $user['role_ids'] = array_map(
        static fn(array $role): int => (int) $role['id'],
        $roles
    );
    $user['permissions'] = $permissions;

    echo json_encode([
        'status' => true,
        'message' => 'تم جلب تفاصيل المستخدم بنجاح',
        'data' => [
            'user' => $user
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء جلب تفاصيل المستخدم',
        // يفيد أثناء التطوير لمعرفة اسم العمود أو الجدول المسبب للمشكلة.
        'error' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
