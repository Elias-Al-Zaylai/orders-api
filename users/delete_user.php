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

if ($userId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'رقم المستخدم مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

// منع حذف الحساب الذي ينفذ الطلب حاليًا.
$authenticatedUserId = (int) ($authUser['id'] ?? 0);
if ($authenticatedUserId === $userId) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'لا يمكنك حذف حسابك الحالي'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $userStatement = $pdo->prepare('SELECT id, name FROM users WHERE id = ? LIMIT 1');
    $userStatement->execute([$userId]);
    $user = $userStatement->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'المستخدم غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    // حذف رموز الدخول وأدوار المستخدم أولًا.
    $deleteTokensStatement = $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?');
    $deleteTokensStatement->execute([$userId]);

    $deleteRolesStatement = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
    $deleteRolesStatement->execute([$userId]);

    // سيمنع MySQL الحذف إذا كان المستخدم مرتبطًا بطلبات أو عمليات أخرى.
    $deleteUserStatement = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $deleteUserStatement->execute([$userId]);

    $pdo->commit();

    echo json_encode([
        'status' => true,
        'message' => 'تم حذف المستخدم بنجاح'
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // الرمز 23000 يظهر غالبًا عندما يكون المستخدم مرتبطًا بسجلات أخرى.
    if (in_array($exception->getCode(), ['23000', '23505', '23503'], true)) {
        http_response_code(409);
        echo json_encode([
            'status' => false,
            'message' => 'لا يمكن حذف المستخدم لأنه مرتبط ببيانات في النظام. يمكنك تعطيله بدلًا من حذفه'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء حذف المستخدم',
        'error' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
