<?php

// تحديد نوع الاستجابة JSON مع دعم اللغة العربية.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول والصلاحية.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// إدارة أنواع الطلبات ضمن صلاحية إدارة الإعدادات.
requirePermission('manage_settings');

try {
    // استقبال البحث والحالة من رابط الطلب.
    $search = trim($_GET['search'] ?? '');
    $isActive = $_GET['is_active'] ?? null;

    // بناء شروط الاستعلام بشكل آمن.
    $conditions = [];
    $parameters = [];

    if ($search !== '') {
        $conditions[] = 'name ILIKE ?';
        $parameters[] = '%' . $search . '%';
    }

    // قبول 0 أو 1 فقط عند إرسال حالة التفعيل.
    if ($isActive !== null && $isActive !== '') {
        if (!in_array((string) $isActive, ['0', '1'], true)) {
            http_response_code(422);

            echo json_encode([
                'status' => false,
                'message' => 'قيمة حالة التفعيل غير صحيحة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $conditions[] = 'is_active = ?';
        $parameters[] = (int) $isActive;
    }

    $whereClause = '';

    if (!empty($conditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $conditions);
    }

    // جلب أنواع الطلبات مرتبة من الأحدث إلى الأقدم.
    $statement = $pdo->prepare("\n        SELECT\n            id,\n            name,\n            is_active,\n            created_at,\n            updated_at\n        FROM request_types\n        {$whereClause}\n        ORDER BY id DESC\n    ");

    $statement->execute($parameters);
    $requestTypes = $statement->fetchAll(PDO::FETCH_ASSOC);

    // تحويل بعض القيم إلى أنواع مناسبة قبل إرسالها.
    foreach ($requestTypes as &$requestType) {
        $requestType['id'] = (int) $requestType['id'];
        $requestType['is_active'] = (int) $requestType['is_active'];
    }
    unset($requestType);

    echo json_encode([
        'status' => true,
        'message' => 'تم جلب أنواع الطلبات بنجاح',
        'data' => $requestTypes,
        'total' => count($requestTypes)
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء جلب أنواع الطلبات'
    ], JSON_UNESCAPED_UNICODE);
}
