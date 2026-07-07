<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('direct_requirement');

try {
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
        o.created_at AS order_created_at,

        u.name AS requester_name,

        rt.name AS request_type_name,

        fc.name AS from_company_name,
        fd.name AS from_department_name,

        tc.name AS to_company_name,
        td.name AS to_department_name

    FROM requirements r

    INNER JOIN orders o
        ON r.order_id = o.id

    INNER JOIN users u
        ON o.requester_id = u.id

    LEFT JOIN request_types rt
        ON o.request_type_id = rt.id

    LEFT JOIN companies fc
        ON o.from_company_id = fc.id

    LEFT JOIN departments fd
        ON o.from_department_id = fd.id

    LEFT JOIN companies tc
        ON o.to_company_id = tc.id

    LEFT JOIN departments td
        ON o.to_department_id = td.id

    WHERE r.status = 'new'
      AND o.status = 'submitted'

    ORDER BY r.created_at DESC
");

    $stmt->execute();

    echo json_encode([
        "status" => true,
        "message" => "تم جلب المطاليب الواردة بنجاح",
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب المطاليب الواردة",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}