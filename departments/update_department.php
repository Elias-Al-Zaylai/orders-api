<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_settings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$departmentId = (int) (
    $data['id'] ?? 0
);

$companyId = (int) (
    $data['company_id'] ?? 0
);

$name = trim(
    $data['name'] ?? ''
);

if ($departmentId <= 0) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "رقم الإدارة غير صحيح"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($companyId <= 0) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "يجب اختيار الشركة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($name === '') {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "اسم الإدارة مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (mb_strlen($name) < 2) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" =>
            "اسم الإدارة يجب ألا يقل عن حرفين"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (mb_strlen($name) > 150) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" =>
            "اسم الإدارة طويل جدًا"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    /*
     * التأكد من وجود الإدارة.
     */
    $departmentStatement = $pdo->prepare("
        SELECT id

        FROM departments

        WHERE id = ?

        LIMIT 1
    ");

    $departmentStatement->execute([
        $departmentId
    ]);

    if (!$departmentStatement->fetch()) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الإدارة غير موجودة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التأكد من وجود الشركة.
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
     * منع تكرار اسم الإدارة
     * داخل نفس الشركة.
     */
    $duplicateStatement = $pdo->prepare("
        SELECT id

        FROM departments

        WHERE company_id = ?
          AND name = ?
          AND id != ?

        LIMIT 1
    ");

    $duplicateStatement->execute([
        $companyId,
        $name,
        $departmentId
    ]);

    if ($duplicateStatement->fetch()) {
        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" =>
                "هذه الإدارة موجودة مسبقًا داخل الشركة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * تحديث الإدارة.
     */
    $statement = $pdo->prepare("
        UPDATE departments

        SET
            company_id = ?,
            name = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $statement->execute([
        $companyId,
        $name,
        $departmentId
    ]);

    /*
     * جلب الإدارة بعد التحديث.
     */
    $updatedStatement = $pdo->prepare("
        SELECT
            d.id,
            d.company_id,
            d.name,
            d.is_active,
            d.created_at,
            d.updated_at,
            c.name AS company_name

        FROM departments d

        INNER JOIN companies c
            ON c.id = d.company_id

        WHERE d.id = ?

        LIMIT 1
    ");

    $updatedStatement->execute([
        $departmentId
    ]);

    $department =
        $updatedStatement->fetch();

    echo json_encode([
        "status" => true,
        "message" =>
            "تم تعديل الإدارة بنجاح",
        "data" => [
            "department" => [
                "id" =>
                    (int) $department['id'],
                "company_id" =>
                    (int) $department['company_id'],
                "company_name" =>
                    $department['company_name'],
                "name" =>
                    $department['name'],
                "is_active" =>
                    (bool) $department['is_active'],
                "created_at" =>
                    $department['created_at'],
                "updated_at" =>
                    $department['updated_at']
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" =>
            "حدث خطأ أثناء تعديل الإدارة",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}