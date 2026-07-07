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

if ($departmentId <= 0) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "رقم الإدارة غير صحيح"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (!array_key_exists('is_active', $data)) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "حالة الإدارة مطلوبة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$rawStatus = $data['is_active'];

if (
    $rawStatus === true ||
    $rawStatus === 1 ||
    $rawStatus === '1'
) {
    $isActive = 1;
} elseif (
    $rawStatus === false ||
    $rawStatus === 0 ||
    $rawStatus === '0'
) {
    $isActive = 0;
} else {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "حالة الإدارة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    /*
     * جلب الإدارة والشركة التابعة لها.
     */
    $departmentStatement = $pdo->prepare("
        SELECT
            d.id,
            d.company_id,
            c.is_active AS company_is_active

        FROM departments d

        INNER JOIN companies c
            ON c.id = d.company_id

        WHERE d.id = ?

        LIMIT 1
    ");

    $departmentStatement->execute([
        $departmentId
    ]);

    $department =
        $departmentStatement->fetch();

    if (!$department) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الإدارة غير موجودة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * لا يمكن تفعيل إدارة
     * تابعة لشركة متوقفة.
     */
    if (
        $isActive === 1 &&
        (int) $department['company_is_active'] !== 1
    ) {
        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" =>
                "لا يمكن تفعيل الإدارة لأن الشركة متوقفة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * تحديث الحالة.
     */
    $statement = $pdo->prepare("
        UPDATE departments

        SET
            is_active = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $statement->execute([
        $isActive,
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

    $updatedDepartment =
        $updatedStatement->fetch();

    echo json_encode([
        "status" => true,
        "message" => $isActive === 1
            ? "تم تفعيل الإدارة بنجاح"
            : "تم إيقاف الإدارة بنجاح",
        "data" => [
            "department" => [
                "id" =>
                    (int) $updatedDepartment['id'],
                "company_id" =>
                    (int) $updatedDepartment['company_id'],
                "company_name" =>
                    $updatedDepartment['company_name'],
                "name" =>
                    $updatedDepartment['name'],
                "is_active" =>
                    (bool) $updatedDepartment['is_active'],
                "created_at" =>
                    $updatedDepartment['created_at'],
                "updated_at" =>
                    $updatedDepartment['updated_at']
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" =>
            "حدث خطأ أثناء تغيير حالة الإدارة",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}