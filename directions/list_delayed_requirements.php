<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('direct_requirement');

try {
    /*
     * موجّه الطلب يشاهد المطاليب المتأخرة التابعة لإدارته فقط.
     * مدير النظام يستطيع مشاهدة كل المطاليب المتأخرة.
     */
    $isAdmin = authUserHasRole('admin');
    $departmentId = (int) ($authUser['department_id'] ?? 0);

    if (!$isAdmin && $departmentId <= 0) {
        echo json_encode([
            "status" => true,
            "message" => "لا توجد مطاليب متأخرة",
            "data" => []
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $whereDepartment = '';
    $params = [];

    if (!$isAdmin) {
        $whereDepartment = "AND o.to_department_id = ?";
        $params[] = $departmentId;
    }

    $stmt = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.order_id,
            r.problem AS title,
            r.problem,
            r.status AS requirement_status,
            r.created_at AS requirement_created_at,

            o.order_number,
            o.document_number,
            o.statement,
            o.status AS order_status,

            requester.name AS requester_name,

            rt.name AS request_type_name,

            tc.name AS to_company_name,
            td.name AS to_department_name,

            rd.id AS direction_id,
            rd.executor_id,
            rd.allowed_start,
            rd.allowed_end,
            rd.notes_to_executor,
            rd.created_at AS direction_created_at,

            executor.name AS executor_name,
            executor.phone AS executor_phone,

            CASE
                WHEN rd.allowed_end IS NULL THEN 0
                ELSE FLOOR(EXTRACT(EPOCH FROM (NOW() - rd.allowed_end)) / 3600)
            END AS delayed_hours

        FROM requirement_directions rd

        INNER JOIN requirements r
            ON rd.requirement_id = r.id

        INNER JOIN orders o
            ON r.order_id = o.id

        INNER JOIN users requester
            ON o.requester_id = requester.id

        INNER JOIN users executor
            ON rd.executor_id = executor.id

        LEFT JOIN request_types rt
            ON o.request_type_id = rt.id

        LEFT JOIN companies tc
            ON o.to_company_id = tc.id

        LEFT JOIN departments td
            ON o.to_department_id = td.id

        WHERE rd.allowed_end IS NOT NULL
          AND rd.allowed_end < NOW()
          AND r.status IN (
              'directed',
              'received_by_executor',
              'returned_to_executor'
          )
          AND o.status NOT IN ('completed', 'cancelled')
          $whereDepartment

        ORDER BY rd.allowed_end ASC
    ");

    $stmt->execute($params);

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($data as &$item) {
        $item['requirement_id'] = (int) $item['requirement_id'];
        $item['order_id'] = (int) $item['order_id'];
        $item['direction_id'] = (int) $item['direction_id'];
        $item['executor_id'] = (int) $item['executor_id'];
        $item['delayed_hours'] = (int) $item['delayed_hours'];
    }
    unset($item);

    echo json_encode([
        "status" => true,
        "message" => "تم جلب المطاليب المتأخرة بنجاح",
        "data" => $data
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب المطاليب المتأخرة",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
