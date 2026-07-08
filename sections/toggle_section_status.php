<?php

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

// التحقق من المستخدم والصلاحية
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية إدارة الإعدادات
requirePermission('manage_settings');

// السماح بطلب POST فقط
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        "status" => false,
        "message" => "طريقة الطلب غير مسموحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// استقبال البيانات
$data = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($data)) {
    $data = [];
}

// رقم القسم
$id =
    (int) ($data['id'] ?? 0);

// التحقق من الرقم
if ($id <= 0) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "رقم القسم مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

    /*
     * جلب القسم وقفل السجل.
     */
    $statement = $pdo->prepare("
        SELECT
            id,
            is_active

        FROM sections

        WHERE id = ?

        LIMIT 1

        FOR UPDATE
    ");

    $statement->execute([
        $id
    ]);

    $section = $statement->fetch();

    if (!$section) {
        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "القسم غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // عكس الحالة الحالية
    $newStatus =
        (int) $section['is_active'] === 1
            ? 0
            : 1;

    /*
     * تحديث الحالة.
     */
    $updateStatement = $pdo->prepare("
        UPDATE sections

        SET
            is_active = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $updateStatement->execute([
        $newStatus,
        $id
    ]);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => $newStatus === 1
            ? "تم تفعيل القسم بنجاح"
            : "تم إيقاف القسم بنجاح",
        "data" => [
            "id" => $id,
            "is_active" => $newStatus
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تغيير حالة القسم",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}