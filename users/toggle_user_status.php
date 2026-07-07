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

try {
    $userStatement = $pdo->prepare('SELECT id, name, is_active FROM users WHERE id = ? LIMIT 1');
    $userStatement->execute([$userId]);
    $user = $userStatement->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'المستخدم غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // إذا أرسل التطبيق is_active نستخدمه، وإلا نعكس الحالة الحالية تلقائيًا.
    $newStatus = array_key_exists('is_active', $data)
        ? ((int) $data['is_active'] === 1 ? 1 : 0)
        : ((int) $user['is_active'] === 1 ? 0 : 1);

    // منع المدير من تعطيل الحساب الذي يستخدمه حاليًا.
    $authenticatedUserId = (int) ($authUser['id'] ?? 0);
    if ($authenticatedUserId === $userId && $newStatus === 0) {
        http_response_code(422);
        echo json_encode(['status' => false, 'message' => 'لا يمكنك تعطيل حسابك الحالي'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    $updateStatement = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
    $updateStatement->execute([$newStatus, $userId]);

    // عند تعطيل المستخدم نحذف جميع رموز دخوله الحالية.
    if ($newStatus === 0) {
        $deleteTokensStatement = $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?');
        $deleteTokensStatement->execute([$userId]);
    }

    $pdo->commit();

    echo json_encode([
        'status' => true,
        'message' => $newStatus === 1
            ? 'تم تفعيل المستخدم بنجاح'
            : 'تم تعطيل المستخدم بنجاح',
        'data' => [
            'user_id' => $userId,
            'is_active' => (bool) $newStatus
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء تغيير حالة المستخدم',
        'error' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
