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

try {
    /*
     * التأكد من وجود الإدارة.
     */
    $departmentStatement = $pdo->prepare("
        SELECT
            id,
            name

        FROM departments

        WHERE id = ?

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
     * التأكد من عدم ارتباط الإدارة
     * بأقسام أو مستخدمين أو طلبات.
     */
    $usageStatement = $pdo->prepare("
        SELECT
            (
                SELECT COUNT(*)
                FROM sections
                WHERE department_id = ?
            ) AS sections_count,

            (
                SELECT COUNT(*)
                FROM users
                WHERE department_id = ?
            ) AS users_count,

            (
                SELECT COUNT(*)
                FROM orders
                WHERE from_department_id = ?
                   OR to_department_id = ?
            ) AS orders_count
    ");

    $usageStatement->execute([
        $departmentId,
        $departmentId,
        $departmentId,
        $departmentId
    ]);

    $usage = $usageStatement->fetch();

    $sectionsCount =
        (int) $usage['sections_count'];

    $usersCount =
        (int) $usage['users_count'];

    $ordersCount =
        (int) $usage['orders_count'];

    if (
        $sectionsCount > 0 ||
        $usersCount > 0 ||
        $ordersCount > 0
    ) {
        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" =>
                "لا يمكن حذف الإدارة لأنها مرتبطة بأقسام أو مستخدمين أو طلبات",
            "data" => [
                "sections_count" =>
                    $sectionsCount,
                "users_count" =>
                    $usersCount,
                "orders_count" =>
                    $ordersCount
            ]
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * حذف الإدارة.
     */
    $statement = $pdo->prepare("
        DELETE FROM departments

        WHERE id = ?
    ");

    $statement->execute([
        $departmentId
    ]);

    echo json_encode([
        "status" => true,
        "message" =>
            "تم حذف الإدارة بنجاح"
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);

    $message =
        "حدث خطأ أثناء حذف الإدارة";

    if (in_array($e->getCode(), ['23000', '23503'], true)) {
        $message =
            "لا يمكن حذف الإدارة لأنها مرتبطة ببيانات أخرى";
    }

    echo json_encode([
        "status" => false,
        "message" => $message,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}