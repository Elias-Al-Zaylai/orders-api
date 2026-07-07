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

try {
    // عند إرسال include_inactive=1 يتم جلب جميع الأدوار.
    $includeInactive = isset($_GET['include_inactive'])
        && (int) $_GET['include_inactive'] === 1;

    /*
     * استخدمنا الحقول الأساسية فقط حتى يبقى الملف متوافقًا
     * مع جدول roles حتى لو لم تكن الحقول الاختيارية موجودة.
     */
    $sql = "
        SELECT
            id,
            name,
            display_name,
            is_active,
            created_at,
            updated_at
        FROM roles
    ";

    if (!$includeInactive) {
        $sql .= " WHERE is_active = TRUE ";
    }

    $sql .= " ORDER BY display_name ASC, id ASC ";

    $statement = $pdo->query($sql);
    $roles = $statement->fetchAll(PDO::FETCH_ASSOC);

    foreach ($roles as &$role) {
        $role['id'] = (int) $role['id'];
        $role['is_active'] = (bool) $role['is_active'];
    }
    unset($role);

    echo json_encode([
        'status' => true,
        'message' => 'تم جلب الأدوار بنجاح',
        'data' => [
            'roles' => $roles
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء جلب الأدوار',
        // يفيد أثناء التطوير لمعرفة خطأ قاعدة البيانات الحقيقي.
        'error' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
