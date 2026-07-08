<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_settings');

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH'
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

$isActive =
    (int) ($data['is_active'] ?? -1);

if ($companyId <= 0) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "رقم الشركة مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (!in_array($isActive, [0, 1], true)) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "حالة الشركة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    /*
     * التحقق من وجود الشركة.
     */
    $companyStatement = $pdo->prepare("
        SELECT
            id,
            name

        FROM companies

        WHERE id = ?

        LIMIT 1
    ");

    $companyStatement->execute([
        $companyId
    ]);

    $existingCompany =
        $companyStatement->fetch();

    if (!$existingCompany) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الشركة غير موجودة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * تحديث حالة الشركة.
     */
    $updateStatement = $pdo->prepare("
        UPDATE companies

        SET
            is_active = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $updateStatement->execute([
        $isActive,
        $companyId
    ]);

    /*
     * جلب الشركة بعد التحديث.
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

    $message = $isActive === 1
        ? "تم تفعيل الشركة بنجاح"
        : "تم إيقاف الشركة بنجاح";

    echo json_encode([
        "status" => true,
        "message" => $message,
        "data" => [
            "company" => $company
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تحديث حالة الشركة",
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}