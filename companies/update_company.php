<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_settings');

if ($_SERVER['REQUEST_METHOD'] !== 'PUT'
    && $_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        "status" => false,
        "message" => "طريقة الطلب غير مسموحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

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

$companyId =
    (int) ($data['company_id'] ?? 0);

$name = trim(
    (string) ($data['name'] ?? '')
);

if ($companyId <= 0) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "رقم الشركة مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

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
     * التحقق من وجود الشركة.
     */
    $companyStatement = $pdo->prepare("
        SELECT id
        FROM companies
        WHERE id = ?
        LIMIT 1
    ");

    $companyStatement->execute([
        $companyId
    ]);

    if (!$companyStatement->fetch()) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الشركة غير موجودة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التحقق من عدم استخدام الاسم في شركة أخرى.
     */
    $duplicateStatement = $pdo->prepare("
        SELECT id
        FROM companies
        WHERE name = ?
        AND id != ?
        LIMIT 1
    ");

    $duplicateStatement->execute([
        $name,
        $companyId
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
     * تعديل اسم الشركة.
     */
    $updateStatement = $pdo->prepare("
        UPDATE companies

        SET
            name = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $updateStatement->execute([
        $name,
        $companyId
    ]);

    /*
     * جلب بيانات الشركة بعد التعديل.
     */
    $updatedCompanyStatement = $pdo->prepare("
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

    $updatedCompanyStatement->execute([
        $companyId
    ]);

    $company = $updatedCompanyStatement->fetch();

    $company['id'] =
        (int) $company['id'];

    $company['is_active'] =
        (bool) $company['is_active'];

    echo json_encode([
        "status" => true,
        "message" => "تم تعديل الشركة بنجاح",
        "data" => [
            "company" => $company
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تعديل الشركة",
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}