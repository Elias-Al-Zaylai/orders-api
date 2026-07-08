<?php

// تحديد نوع الاستجابة بصيغة JSON مع دعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// السماح فقط للمستخدم الذي يمتلك صلاحية توجيه المطاليب
requirePermission('direct_requirement');

$orderId = (int) ($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم الطلب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $isAdmin = authUserHasRole('admin');
    $departmentId = (int) ($authUser['department_id'] ?? 0);

    if (!$isAdmin && $departmentId <= 0) {
        http_response_code(403);

        echo json_encode([
            "status" => false,
            "message" => "لا توجد إدارة مرتبطة بالمستخدم الحالي"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $whereDepartment = '';
    $params = [$orderId];

    if (!$isAdmin) {
        $whereDepartment = "AND o.to_department_id = ?";
        $params[] = $departmentId;
    }

    /*
     * جلب بيانات الطلب مع التأكد أنه تابع لإدارة الموجّه.
     */
    $orderStmt = $pdo->prepare("
        SELECT
            o.id AS order_id,
            o.order_number,
            o.document_number,
            o.statement,
            o.notes,
            o.status AS order_status,
            o.created_at AS order_created_at,

            requester.name AS requester_name,

            rt.name AS request_type_name,

            from_company.name AS from_company_name,
            from_department.name AS from_department_name,

            to_company.name AS to_company_name,
            to_department.name AS to_department_name

        FROM orders o

        INNER JOIN users requester
            ON requester.id = o.requester_id

        LEFT JOIN request_types rt
            ON rt.id = o.request_type_id

        LEFT JOIN companies from_company
            ON from_company.id = o.from_company_id

        LEFT JOIN departments from_department
            ON from_department.id = o.from_department_id

        LEFT JOIN companies to_company
            ON to_company.id = o.to_company_id

        LEFT JOIN departments to_department
            ON to_department.id = o.to_department_id

        WHERE o.id = ?
          $whereDepartment

        LIMIT 1
    ");

    $orderStmt->execute($params);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الطلب غير موجود أو لا يتبع إدارة الموجّه"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $requirementsStmt = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.order_id,
            r.problem AS title,
            r.problem,
            r.status AS requirement_status,
            r.created_at AS requirement_created_at,
            r.updated_at AS requirement_updated_at

        FROM requirements r

        WHERE r.order_id = ?
          AND r.status = 'new'
          AND NOT EXISTS (
              SELECT 1
              FROM requirement_directions rd
              WHERE rd.requirement_id = r.id
          )

        ORDER BY r.created_at ASC
    ");

    $requirementsStmt->execute([$orderId]);
    $requirements = $requirementsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($requirements as &$requirement) {
        $requirement['requirement_id'] = (int) $requirement['requirement_id'];
        $requirement['order_id'] = (int) $requirement['order_id'];
    }
    unset($requirement);

    $order['order_id'] = (int) $order['order_id'];

    echo json_encode([
        "status" => true,
        "message" => "تم جلب بيانات الطلب والمطاليب الجديدة بنجاح",
        "order" => $order,
        "requirements_count" => count($requirements),
        "requirements" => $requirements
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب مطاليب الطلب",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
