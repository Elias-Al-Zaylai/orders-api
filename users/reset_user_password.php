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
$newPassword = (string) ($data['new_password'] ?? $data['password'] ?? '');

if ($userId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'رقم المستخدم مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($newPassword) < 6) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'كلمة المرور الجديدة يجب ألا تقل عن 6 أحرف'], JSON_UNESCAPED_UNICODE);
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

    $pdo->beginTransaction();

    // تشفير كلمة المرور الجديدة قبل تخزينها.
    $updateStatement = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $updateStatement->execute([
        password_hash($newPassword, PASSWORD_DEFAULT),
        $userId
    ]);

    // حذف جميع الجلسات الحالية حتى لا تبقى الأجهزة القديمة متصلة.
    $deleteTokensStatement = $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?');
    $deleteTokensStatement->execute([$userId]);

    $pdo->commit();

    echo json_encode([
        'status' => true,
        'message' => 'تم تغيير كلمة مرور المستخدم بنجاح'
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء تغيير كلمة المرور',
        'error' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
