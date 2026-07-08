<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

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
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "بيانات الطلب غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// اسم الشركة
$name = trim(
    (string) ($data['name'] ?? '')
);

// حالة الشركة
$isActive = isset($data['is_active'])
    ? ((int) $data['is_active'] === 1 ? 1 : 0)
    : 1;

// التحقق من الاسم
if ($name === '') {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "اسم الشركة مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (mb_strlen($name) < 2) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "اسم الشركة يجب أن يتكون من حرفين على الأقل"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (mb_strlen($name) > 150) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "اسم الشركة طويل جدًا"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    /*
     * التحقق من عدم تكرار اسم الشركة.
     */
    $duplicateStatement = $pdo->prepare("
        SELECT id
        FROM companies
        WHERE name = ?
        LIMIT 1
    ");

    $duplicateStatement->execute([
        $name
    ]);

    if ($duplicateStatement->fetch()) {
        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "اسم الشركة موجود مسبقًا"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * إضافة الشركة.
     */
    $insertStatement = $pdo->prepare("
        INSERT INTO companies (
            name,
            is_active,
            created_at,
            updated_at
        ) VALUES (
            ?,
            ?,
            NOW(),
            NOW()
        )
        RETURNING id
    ");

    $insertStatement->execute([
        $name,
        $isActive
    ]);

    $companyId = (int) $insertStatement->fetchColumn();

    /*
     * جلب الشركة بعد الإضافة.
     */
    $companyStatement = $pdo->prepare("
        SELECT
            id,
            name,
            is_active,
            created_at,
            updated_at

        FROM companies

        WHERE id = ?

        LIMIT 1
    ");

    $companyStatement->execute([
        $companyId
    ]);

    $company = $companyStatement->fetch();

    $company['id'] =
        (int) $company['id'];

    $company['is_active'] =
        (bool) $company['is_active'];

    http_response_code(201);

    echo json_encode([
        "status" => true,
        "message" => "تمت إضافة الشركة بنجاح",
        "data" => [
            "company" => $company
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء إضافة الشركة",
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}