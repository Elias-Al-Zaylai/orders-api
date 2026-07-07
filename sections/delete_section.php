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
    /*
     * التحقق من وجود القسم.
     */
    $sectionStatement = $pdo->prepare("
        SELECT id

        FROM sections

        WHERE id = ?

        LIMIT 1
    ");

    $sectionStatement->execute([
        $id
    ]);

    if (!$sectionStatement->fetch()) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "القسم غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * حذف القسم.
     *
     * إذا كان القسم مرتبطًا بمستخدمين أو طلبات،
     * ستمنع قاعدة البيانات الحذف عن طريق Foreign Key.
     */
    $deleteStatement = $pdo->prepare("
        DELETE FROM sections

        WHERE id = ?
    ");

    $deleteStatement->execute([
        $id
    ]);

    echo json_encode([
        "status" => true,
        "message" => "تم حذف القسم بنجاح"
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    /*
     * الخطأ 23000 يعني وجود ارتباطات تمنع الحذف.
     */
    if (in_array($e->getCode(), ['23000', '23503'], true)) {
        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكن حذف القسم لأنه مرتبط بمستخدمين أو طلبات"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء حذف القسم",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}