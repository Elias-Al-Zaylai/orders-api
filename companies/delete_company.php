<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_settings');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE'
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

if ($companyId <= 0) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "رقم الشركة مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

    /*
     * جلب الشركة وقفل السجل أثناء الحذف.
     */
    $companyStatement = $pdo->prepare("
        SELECT
            id,
            name

        FROM companies

        WHERE id = ?

        LIMIT 1

        FOR UPDATE
    ");

    $companyStatement->execute([
        $companyId
    ]);

    $company = $companyStatement->fetch();

    if (!$company) {
        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الشركة غير موجودة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التحقق من الإدارات المرتبطة بالشركة.
     */
    $departmentsStatement = $pdo->prepare("
        SELECT COUNT(*)
        FROM departments
        WHERE company_id = ?
    ");

    $departmentsStatement->execute([
        $companyId
    ]);

    $departmentsCount =
        (int) $departmentsStatement->fetchColumn();

    /*
     * التحقق من المستخدمين المرتبطين بالشركة.
     */
    $usersStatement = $pdo->prepare("
        SELECT COUNT(*)
        FROM users
        WHERE company_id = ?
    ");

    $usersStatement->execute([
        $companyId
    ]);

    $usersCount =
        (int) $usersStatement->fetchColumn();

    /*
     * التحقق من الطلبات المرتبطة بالشركة.
     */
    $ordersStatement = $pdo->prepare("
        SELECT COUNT(*)
        FROM orders

        WHERE from_company_id = ?
        OR to_company_id = ?
    ");

    $ordersStatement->execute([
        $companyId,
        $companyId
    ]);

    $ordersCount =
        (int) $ordersStatement->fetchColumn();

    if (
        $departmentsCount > 0
        || $usersCount > 0
        || $ordersCount > 0
    ) {
        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكن حذف الشركة لأنها مرتبطة بإدارات أو مستخدمين أو طلبات، يمكنك إيقافها بدلًا من حذفها",
            "data" => [
                "departments_count" => $departmentsCount,
                "users_count" => $usersCount,
                "orders_count" => $ordersCount
            ]
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * حذف الشركة.
     */
    $deleteStatement = $pdo->prepare("
        DELETE FROM companies
        WHERE id = ?
    ");

    $deleteStatement->execute([
        $companyId
    ]);

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم حذف الشركة بنجاح",
        "data" => [
            "company_id" => $companyId
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء حذف الشركة",
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}